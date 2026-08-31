<?php

namespace App\Http\Controllers;

use App\Models\ShopAccount;
use App\Services\ChartOfAccountsSeeder;
use App\Services\ShopAccountBalanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShopAccountController extends Controller
{
    /**
     * لیست حساب‌های فروشگاه و تنخواه‌ها همراه با موجودی.
     * GET /api/shop-accounts
     * GET /api/shop-accounts?type=petty_cash
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        ShopAccount::ensureDefaultsForAtelier($atelierId);

        $request->validate([
            'type' => ['sometimes', Rule::in(ShopAccount::TYPES)],
        ]);

        $includeInactive = $request->boolean('include_inactive');
        $accounts = ShopAccount::query()
            ->forAtelier($atelierId)
            ->when(! $includeInactive, fn ($q) => $q->active())
            ->when(
                $request->filled('type') && ShopAccount::supportsTypes(),
                fn ($q) => $request->input('type') === ShopAccount::TYPE_SHOP
                    ? $q->shopType()
                    : $q->ofType($request->input('type'))
            )
            ->when(ShopAccount::supportsTypes(), fn ($q) => $q->orderBy('type'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $breakdown = ShopAccountBalanceService::breakdown($atelierId, $accounts->pluck('id')->all());

        return response([
            'data' => $accounts->map(fn (ShopAccount $a) => $this->serialize($a, $breakdown))->values(),
        ], 200);
    }

    /**
     * ایجاد حساب فروشگاه یا تنخواه.
     * POST /api/shop-accounts  { "name": "تنخواه آشپزخانه", "type": "petty_cash" }
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ایجاد حساب فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        ShopAccount::ensureDefaultsForAtelier($atelierId);

        $fields = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('shop_accounts', 'name')->where(fn ($q) => $q->where('atelier_id', $atelierId)->where('is_active', true)),
            ],
            'type' => ['sometimes', Rule::in(ShopAccount::TYPES)],
            'sort_order' => 'sometimes|integer|min:0|max:9999',
        ]);

        $type = $fields['type'] ?? ShopAccount::TYPE_SHOP;
        $supportsTypes = ShopAccount::supportsTypes();

        if ($type === ShopAccount::TYPE_PETTY_CASH && ! $supportsTypes) {
            return response()->json([
                'message' => 'ساختار تنخواه هنوز روی دیتابیس اعمال نشده است. migration یا فایل SQL را اجرا کنید.',
            ], 422);
        }

        $maxSort = (int) ShopAccount::query()
            ->forAtelier($atelierId)
            ->when($supportsTypes, fn ($q) => $q->where('type', $type))
            ->max('sort_order');

        $payload = [
            'atelier_id' => $atelierId,
            'name' => trim($fields['name']),
            'sort_order' => $fields['sort_order'] ?? ($maxSort + 1),
            'legacy_slot' => null,
            'is_active' => true,
        ];
        if ($supportsTypes) {
            $payload['type'] = $type;
        }

        $account = ShopAccount::create($payload);
        ChartOfAccountsSeeder::syncShopAccount($account);

        return response([
            'message' => $type === ShopAccount::TYPE_PETTY_CASH
                ? 'حساب تنخواه ایجاد شد.'
                : 'حساب فروشگاه ایجاد شد.',
            'data' => $this->serialize($account, []),
        ], 201);
    }

    /**
     * ویرایش نام / ترتیب حساب.
     * PUT /api/shop-accounts/{shopAccount}
     */
    public function update(Request $request, ShopAccount $shopAccount)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null || (int) $shopAccount->atelier_id !== $atelierId) {
            return response()->json(['message' => 'یافت نشد'], 404);
        }

        $fields = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('shop_accounts', 'name')
                    ->where(fn ($q) => $q->where('atelier_id', $atelierId)->where('is_active', true))
                    ->ignore($shopAccount->id),
            ],
            'sort_order' => 'sometimes|integer|min:0|max:9999',
            'is_active' => 'sometimes|boolean',
        ]);

        if (array_key_exists('name', $fields)) {
            $shopAccount->name = trim($fields['name']);
        }
        if (array_key_exists('sort_order', $fields)) {
            $shopAccount->sort_order = $fields['sort_order'];
        }
        if (array_key_exists('is_active', $fields)) {
            $shopAccount->is_active = (bool) $fields['is_active'];
        }

        $shopAccount->save();
        ChartOfAccountsSeeder::syncShopAccount($shopAccount);

        $breakdown = ShopAccountBalanceService::breakdown($atelierId, [$shopAccount->id]);

        return response([
            'message' => 'حساب به‌روزرسانی شد.',
            'data' => $this->serialize($shopAccount->fresh(), $breakdown),
        ], 200);
    }

    /**
     * غیرفعال‌سازی حساب (حذف نرم — دادهٔ واریزها و هزینه‌ها حفظ می‌شود).
     * DELETE /api/shop-accounts/{shopAccount}
     */
    public function destroy(Request $request, ShopAccount $shopAccount)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null || (int) $shopAccount->atelier_id !== $atelierId) {
            return response()->json(['message' => 'یافت نشد'], 404);
        }

        if ($shopAccount->legacy_slot) {
            return response()->json([
                'message' => 'حساب‌های پیش‌فرض (حساب ۱ و حساب ۲) قابل حذف نیستند. می‌توانید نامشان را ویرایش کنید.',
            ], 422);
        }

        $shopAccount->is_active = false;
        $shopAccount->save();
        ChartOfAccountsSeeder::syncShopAccount($shopAccount);

        return response([
            'message' => 'حساب غیرفعال شد.',
            'data' => $this->serialize(
                $shopAccount,
                ShopAccountBalanceService::breakdown($atelierId, [$shopAccount->id])
            ),
        ], 200);
    }

    /**
     * @param  array<int, array<string, float>>  $breakdown
     * @return array<string, mixed>
     */
    protected function serialize(ShopAccount $account, array $breakdown): array
    {
        $row = $breakdown[$account->id] ?? [];

        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type ?: ShopAccount::TYPE_SHOP,
            'type_label' => $account->typeLabel(),
            'is_petty_cash' => $account->isPettyCash(),
            'sort_order' => (int) $account->sort_order,
            'legacy_slot' => $account->legacy_slot,
            'is_active' => (bool) $account->is_active,
            'balance' => round((float) ($row['balance'] ?? 0), 2),
            'deposits_total' => round((float) ($row['deposits'] ?? 0), 2),
            'charged_total' => round((float) ($row['transfers_in'] ?? 0), 2),
            'transferred_out_total' => round((float) ($row['transfers_out'] ?? 0), 2),
            'expenses_total' => round((float) ($row['expenses'] ?? 0), 2),
            'invoices_total' => round((float) ($row['invoices'] ?? 0), 2),
            'manual_purchases_total' => round((float) ($row['manual_purchases'] ?? 0), 2),
            'manual_sales_total' => round((float) ($row['manual_sales'] ?? 0), 2),
        ];
    }
}
