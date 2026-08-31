<?php

namespace App\Http\Controllers;

use App\Models\AccountingAccount;
use App\Models\ShopAccount;
use App\Services\ChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingAccountController extends Controller
{
    /**
     * درخت حساب فروشگاه.
     * GET /api/accounting/accounts
     * GET /api/accounting/accounts?include_inactive=1
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        if (! AccountingAccount::tableReady()) {
            return response()->json([
                'message' => 'جدول حسابداری وجود ندارد. migration یا فایل SQL را اجرا کنید.',
            ], 422);
        }

        ShopAccount::ensureDefaultsForAtelier($atelierId);
        ChartOfAccountsSeeder::ensureForAtelier($atelierId);

        $includeInactive = $request->boolean('include_inactive');
        $roots = ChartOfAccountsSeeder::treeForAtelier($atelierId, $includeInactive);

        return response([
            'data' => array_map(
                fn (AccountingAccount $root) => $root->toApiArray(true),
                $roots
            ),
        ], 200);
    }

    /**
     * ساخت معین یا تفصیلی غیرسیستمی.
     * POST /api/accounting/accounts
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ایجاد حساب فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        if (! AccountingAccount::tableReady()) {
            return response()->json([
                'message' => 'جدول حسابداری وجود ندارد. migration یا فایل SQL را اجرا کنید.',
            ], 422);
        }

        ChartOfAccountsSeeder::ensureForAtelier($atelierId);

        $fields = $request->validate([
            'parent_id' => [
                'required',
                'integer',
                Rule::exists('accounting_accounts', 'id')->where(fn ($q) => $q->where('atelier_id', $atelierId)),
            ],
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('accounting_accounts', 'code')->where(fn ($q) => $q->where('atelier_id', $atelierId)),
            ],
            'name' => 'required|string|max:255',
            'level' => ['required', Rule::in([AccountingAccount::LEVEL_MOEIN, AccountingAccount::LEVEL_TAFSILI])],
        ]);

        $parent = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->where('id', (int) $fields['parent_id'])
            ->first();
        if (! $parent) {
            return response()->json(['message' => 'حساب والد یافت نشد.'], 422);
        }

        if ($fields['level'] === AccountingAccount::LEVEL_MOEIN && $parent->level !== AccountingAccount::LEVEL_KOL) {
            return response()->json(['message' => 'معین باید زیر حساب کل ساخته شود.'], 422);
        }
        if ($fields['level'] === AccountingAccount::LEVEL_TAFSILI && $parent->level !== AccountingAccount::LEVEL_MOEIN) {
            return response()->json(['message' => 'تفصیلی باید زیر حساب معین ساخته شود.'], 422);
        }

        $account = AccountingAccount::create([
            'atelier_id' => $atelierId,
            'parent_id' => $parent->id,
            'code' => trim($fields['code']),
            'name' => trim($fields['name']),
            'level' => $fields['level'],
            'nature' => $parent->nature,
            'kind' => $parent->kind,
            'is_system' => false,
            'is_active' => true,
        ]);

        return response([
            'message' => 'حساب ایجاد شد.',
            'data' => $account->toApiArray(),
        ], 201);
    }

    /**
     * ویرایش نام / وضعیت حساب غیرسیستمی.
     * PUT /api/accounting/accounts/{accountingAccount}
     */
    public function update(Request $request, AccountingAccount $accountingAccount)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null || (int) $accountingAccount->atelier_id !== $atelierId) {
            return response()->json(['message' => 'یافت نشد'], 404);
        }

        if ($accountingAccount->is_system) {
            return response()->json([
                'message' => 'حساب‌های سیستمی از این مسیر ویرایش نمی‌شوند.',
            ], 422);
        }

        $fields = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        if (array_key_exists('name', $fields)) {
            $accountingAccount->name = trim($fields['name']);
        }
        if (array_key_exists('is_active', $fields)) {
            $accountingAccount->is_active = (bool) $fields['is_active'];
        }
        $accountingAccount->save();

        return response([
            'message' => 'حساب به‌روزرسانی شد.',
            'data' => $accountingAccount->fresh()->toApiArray(),
        ], 200);
    }
}
