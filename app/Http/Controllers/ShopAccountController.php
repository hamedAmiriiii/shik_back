<?php

namespace App\Http\Controllers;

use App\Models\ShopAccount;
use App\Services\DailyShopReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShopAccountController extends Controller
{
    /**
     * لیست حساب‌های فروشگاه همراه با موجودی (مجموع واریزهای تطبیق روزانه).
     * GET /api/shop-accounts
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        ShopAccount::ensureDefaultsForAtelier($atelierId);

        $includeInactive = $request->boolean('include_inactive');
        $accounts = ShopAccount::query()
            ->forAtelier($atelierId)
            ->when(! $includeInactive, fn ($q) => $q->active())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $balances = DailyShopReconciliationService::balancesByAccountId($atelierId, $accounts->pluck('id')->all());

        return response([
            'data' => $accounts->map(fn (ShopAccount $a) => $this->serialize($a, $balances))->values(),
        ], 200);
    }

    /**
     * ایجاد حساب جدید برای فروشگاه.
     * POST /api/shop-accounts
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
            'sort_order' => 'sometimes|integer|min:0|max:9999',
        ]);

        $maxSort = (int) ShopAccount::query()->forAtelier($atelierId)->max('sort_order');

        $account = ShopAccount::create([
            'atelier_id' => $atelierId,
            'name' => trim($fields['name']),
            'sort_order' => $fields['sort_order'] ?? ($maxSort + 1),
            'legacy_slot' => null,
            'is_active' => true,
        ]);

        return response([
            'message' => 'حساب با موفقیت ایجاد شد.',
            'data' => $this->serialize($account, [$account->id => 0.0]),
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

        $balances = DailyShopReconciliationService::balancesByAccountId($atelierId, [$shopAccount->id]);

        return response([
            'message' => 'حساب به‌روزرسانی شد.',
            'data' => $this->serialize($shopAccount->fresh(), $balances),
        ], 200);
    }

    /**
     * غیرفعال‌سازی حساب (حذف نرم — دادهٔ واریزها حفظ می‌شود).
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

        return response([
            'message' => 'حساب غیرفعال شد.',
            'data' => $this->serialize(
                $shopAccount,
                DailyShopReconciliationService::balancesByAccountId($atelierId, [$shopAccount->id])
            ),
        ], 200);
    }

    /**
     * @param  array<int, float>  $balances
     * @return array<string, mixed>
     */
    protected function serialize(ShopAccount $account, array $balances): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'sort_order' => (int) $account->sort_order,
            'legacy_slot' => $account->legacy_slot,
            'is_active' => (bool) $account->is_active,
            'balance' => round((float) ($balances[$account->id] ?? 0), 2),
        ];
    }
}
