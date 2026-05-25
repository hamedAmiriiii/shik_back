<?php

namespace App\Http\Controllers;

use App\Models\DailyShopReconciliation;
use App\Services\DailyShopReconciliationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Morilog\Jalali\Jalalian;

class DailyShopReconciliationController extends Controller
{
    /**
     * گرید تطبیق روزانه — فیلتر ماه شمسی (پیش‌فرض: ماه جاری).
     * GET /api/daily-reconciliations
     * GET /api/daily-reconciliations?year=1404&month=3
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $now = Jalalian::fromCarbon(Carbon::now('Asia/Tehran'));
        $request->validate([
            'year' => 'sometimes|integer|min:1300|max:1500',
            'month' => 'sometimes|integer|min:1|max:12',
        ]);

        $year = $request->has('year')
            ? (int) $request->input('year')
            : (int) $now->getYear();
        $month = $request->has('month')
            ? (int) $request->input('month')
            : (int) $now->getMonth();

        $data = DailyShopReconciliationService::gridForMonth($atelierId, $year, $month);

        return response(array_merge($data, [
            'meta' => [
                'atelier_id' => $atelierId,
                'reconciliations_table_ready' => \Illuminate\Support\Facades\Schema::hasTable('daily_shop_reconciliations'),
            ],
        ]), 200);
    }

    /**
     * جزئیات یک روز.
     * GET /api/daily-reconciliations/{date}
     */
    public function show(Request $request, string $date)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $dateKey = $this->parseDateKey($request, $date);

        $recon = DailyShopReconciliation::query()
            ->where('atelier_id', $atelierId)
            ->whereDate('date', $dateKey)
            ->first();

        $metrics = \App\Services\ShopSalesReportService::salesAndProfitForDate(
            $atelierId,
            Carbon::parse($dateKey, 'Asia/Tehran')
        );

        return response([
            'date' => $dateKey,
            'date_jalali' => \Morilog\Jalali\Jalalian::fromCarbon(
                Carbon::parse($dateKey, 'Asia/Tehran')
            )->format('Y-m-d'),
            'editable' => DailyShopReconciliationService::isDateEditable($dateKey),
            'sales' => [
                'total_sales' => (float) $metrics['sales'],
                'card_amount' => (float) $metrics['card_amount'],
                'cash_amount' => (float) $metrics['cash_amount'],
                'installments_collected' => (float) $metrics['installments_collected'],
                'total_collected' => (float) $metrics['total_collected'],
                'credit_used_total' => (float) $metrics['credit_used_total'],
                'settlement_total' => (float) $metrics['settlement_total'],
            ],
            'reconciliation' => $recon,
        ], 200);
    }

    /**
     * ثبت / ویرایش واریز روز (تا ۳ روز قبل).
     * POST /api/daily-reconciliations
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت تطبیق روزانه فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $fields = $request->validate([
            'date' => 'required|date',
            'deposit_account_1' => 'required|numeric|min:0',
            'deposit_account_2' => 'required|numeric|min:0',
            'deposit_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);

        $user = $this->requireStaffShopUser($request);
        $dateKey = Carbon::parse($fields['date'])->setTimezone('Asia/Tehran')->format('Y-m-d');

        try {
            $recon = DailyShopReconciliationService::upsert(
                $atelierId,
                $dateKey,
                [
                    'deposit_account_1' => $fields['deposit_account_1'],
                    'deposit_account_2' => $fields['deposit_account_2'],
                    'deposit_cash' => $fields['deposit_cash'],
                ],
                trim($user->name.' '.$user->last_name),
                $fields['notes'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $jalali = Jalalian::fromCarbon(Carbon::parse($dateKey, 'Asia/Tehran'));
        $grid = DailyShopReconciliationService::gridForMonth(
            $atelierId,
            (int) $jalali->getYear(),
            (int) $jalali->getMonth()
        );
        $row = collect($grid['daily'])->firstWhere('date', $dateKey);

        return response([
            'message' => 'تطبیق روز با موفقیت ثبت شد.',
            'reconciliation' => $recon,
            'row' => $row,
        ], 201);
    }

    protected function parseDateKey(Request $request, string $date): string
    {
        $request->merge(['route_date' => $date]);
        $request->validate(['route_date' => 'date']);

        return Carbon::parse($date)->setTimezone('Asia/Tehran')->format('Y-m-d');
    }
}
