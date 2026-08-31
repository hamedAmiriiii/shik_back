<?php

namespace App\Http\Controllers;

use App\Models\AccountingVoucher;
use App\Services\AccountingReportService;
use Illuminate\Http\Request;
use RuntimeException;

class AccountingReportController extends Controller
{
    /**
     * تراز آزمایشی.
     * GET /api/accounting/trial-balance?from=&to=&include_zero=1
     */
    public function trialBalance(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ($fail = $this->tablesGuard()) {
            return $fail;
        }

        try {
            $data = AccountingReportService::trialBalance(
                $atelierId,
                $request->input('from'),
                $request->input('to'),
                $request->boolean('include_zero')
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['data' => $data], 200);
    }

    /**
     * دفتر معین / تفصیلی.
     * GET /api/accounting/ledger?account_id=&from=&to=
     */
    public function ledger(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ($fail = $this->tablesGuard()) {
            return $fail;
        }

        $fields = $request->validate([
            'account_id' => 'required|integer|min:1',
            'from' => 'nullable|string',
            'to' => 'nullable|string',
        ]);

        try {
            $data = AccountingReportService::ledger(
                $atelierId,
                (int) $fields['account_id'],
                $fields['from'] ?? null,
                $fields['to'] ?? null
            );
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'حساب یافت نشد.' ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        }

        return response(['data' => $data], 200);
    }

    /**
     * سود و زیان دوره.
     * GET /api/accounting/profit-loss?from=&to=
     */
    public function profitLoss(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ($fail = $this->tablesGuard()) {
            return $fail;
        }

        try {
            $data = AccountingReportService::profitLoss(
                $atelierId,
                $request->input('from'),
                $request->input('to')
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['data' => $data], 200);
    }

    /**
     * ترازنامه در یک تاریخ.
     * GET /api/accounting/balance-sheet?as_of=
     */
    public function balanceSheet(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ($fail = $this->tablesGuard()) {
            return $fail;
        }

        try {
            $data = AccountingReportService::balanceSheet(
                $atelierId,
                $request->input('as_of')
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['data' => $data], 200);
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
