<?php

namespace App\Http\Controllers;

use App\Models\AccountingVoucher;
use App\Services\AccountingPeriodCloseService;
use Illuminate\Http\Request;
use RuntimeException;

class AccountingPeriodCloseController extends Controller
{
    /**
     * وضعیت دوره‌های بسته‌شده.
     * GET /api/accounting/period-close
     */
    public function status(Request $request)
    {
        $atelierId = $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::ACCOUNTING, 'حسابداری برای این فروشگاه فعال نیست.');
        if ($fail = $this->tablesGuard()) {
            return $fail;
        }

        return response(['data' => AccountingPeriodCloseService::status($atelierId)], 200);
    }

    /**
     * پیش‌نمایش بستن سال یا میان‌دوره.
     * GET /api/accounting/period-close/preview?mode=year&year=1404
     * GET /api/accounting/period-close/preview?mode=mid&as_of=1404-06-31
     */
    public function preview(Request $request)
    {
        $atelierId = $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::ACCOUNTING, 'حسابداری برای این فروشگاه فعال نیست.');
        if ($fail = $this->tablesGuard()) {
            return $fail;
        }

        try {
            $data = AccountingPeriodCloseService::preview(
                $atelierId,
                (string) $request->input('mode', AccountingPeriodCloseService::MODE_YEAR),
                $request->filled('year') ? (int) $request->input('year') : null,
                $request->input('as_of')
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['data' => $data], 200);
    }

    /**
     * ثبت سند بستن.
     * POST /api/accounting/period-close
     * POST /api/accounting/year-close  (alias: { "year": 1404 })
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'بستن دوره فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $fields = $request->validate([
            'mode' => 'nullable|string|in:year,mid',
            'year' => 'nullable|integer|min:1300|max:1600',
            'as_of' => 'nullable|string',
        ]);

        $mode = $fields['mode'] ?? ($request->filled('as_of') && ! $request->filled('year')
            ? AccountingPeriodCloseService::MODE_MID
            : AccountingPeriodCloseService::MODE_YEAR);

        try {
            $result = AccountingPeriodCloseService::post(
                $atelierId,
                $mode,
                isset($fields['year']) ? (int) $fields['year'] : null,
                $fields['as_of'] ?? null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $already = (bool) $result['already_posted'];

        return response([
            'ok' => true,
            'already_posted' => $already,
            'message' => $already
                ? 'سند بستن این تاریخ از قبل ثبت شده است.'
                : 'سند بستن دوره ثبت شد. ثبت رویداد تا این تاریخ قفل شد.',
            'data' => $result['voucher'] ? $result['voucher']->toApiArray() : null,
            'preview' => $result['preview'],
        ], $already ? 200 : 201);
    }

    /**
     * برگشت آخرین سند بستن (بازگشایی قفل).
     * POST /api/accounting/period-close/reopen
     */
    public function reopen(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'بازگشایی دوره فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        try {
            $storno = AccountingPeriodCloseService::reopenLatest($atelierId);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'ok' => true,
            'message' => 'آخرین بستن برگشت خورد و قفل دوره برداشته شد.',
            'data' => $storno->toApiArray(),
        ], 200);
    }

    protected function tablesGuard()
    {
        if (! AccountingVoucher::tablesReady() || ! \App\Models\AccountingAccount::tableReady()) {
            return response()->json([
                'message' => 'جدول سند حسابداری وجود ندارد. migration یا فایل SQL را اجرا کنید.',
            ], 422);
        }

        return null;
    }
}
