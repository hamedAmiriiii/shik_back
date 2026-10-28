<?php

namespace App\Http\Controllers;

use App\Models\AccountingAccount;
use App\Models\AccountingPostingMap;
use App\Services\AccountingPostingMapService;
use App\Services\ChartOfAccountsSeeder;
use Illuminate\Http\Request;
use RuntimeException;

class AccountingPostingMapController extends Controller
{
    /**
     * GET /api/accounting/posting-maps
     */
    public function index(Request $request)
    {
        $atelierId = $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::ACCOUNTING, 'حسابداری برای این فروشگاه فعال نیست.');

        if (! AccountingAccount::tableReady()) {
            return response()->json([
                'message' => 'جدول حسابداری وجود ندارد. migration یا فایل SQL را اجرا کنید.',
            ], 422);
        }

        ChartOfAccountsSeeder::ensureForAtelier($atelierId);

        return response([
            'table_ready' => AccountingPostingMap::tableReady(),
            'data' => AccountingPostingMapService::catalogForAtelier($atelierId),
        ], 200);
    }

    /**
     * PUT /api/accounting/posting-maps/{eventKey}
     * body: { account_id: number|null } — null یعنی بازگشت به پیش‌فرض
     */
    public function update(Request $request, string $eventKey)
    {
        $atelierId = $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::ACCOUNTING, 'حسابداری برای این فروشگاه فعال نیست.');

        if (! AccountingPostingMap::tableReady()) {
            return response()->json([
                'message' => 'جدول نگاشت حسابداری وجود ندارد. فایل create_accounting_posting_maps_manual.sql را اجرا کنید.',
            ], 422);
        }

        $validated = $request->validate([
            'account_id' => 'nullable|integer|min:1',
        ]);

        try {
            AccountingPostingMapService::setMap(
                $atelierId,
                $eventKey,
                isset($validated['account_id']) ? (int) $validated['account_id'] : null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $row = collect(AccountingPostingMapService::catalogForAtelier($atelierId))
            ->firstWhere('event_key', $eventKey);

        return response([
            'message' => 'ذخیره شد.',
            'data' => $row,
        ], 200);
    }
}
