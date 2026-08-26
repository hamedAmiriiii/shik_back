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
                'shop_accounts_table_ready' => \Illuminate\Support\Facades\Schema::hasTable('shop_accounts'),
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
            ->with(['accountDeposits.shopAccount'])
            ->first();

        $metrics = \App\Services\ShopSalesReportService::salesAndProfitForDate(
            $atelierId,
            Carbon::parse($dateKey, 'Asia/Tehran')
        );

        $shopAccounts = DailyShopReconciliationService::activeAccounts($atelierId);
        $balances = DailyShopReconciliationService::balancesByAccountId(
            $atelierId,
            $shopAccounts->pluck('id')->all()
        );

        $accountDeposits = [];
        if ($recon) {
            $byId = $recon->accountDeposits->keyBy('shop_account_id');
            foreach ($shopAccounts as $account) {
                $line = $byId->get($account->id);
                $amount = $line ? (float) $line->amount : 0.0;
                if (! $line && $account->legacy_slot === 'account_1') {
                    $amount = (float) $recon->deposit_account_1;
                } elseif (! $line && $account->legacy_slot === 'account_2') {
                    $amount = (float) $recon->deposit_account_2;
                }
                $accountDeposits[] = [
                    'shop_account_id' => $account->id,
                    'name' => $account->name,
                    'legacy_slot' => $account->legacy_slot,
                    'amount' => $amount,
                    'deposit_record_id' => $line->deposit_record_id ?? null,
                ];
            }
        } else {
            foreach ($shopAccounts as $account) {
                $accountDeposits[] = [
                    'shop_account_id' => $account->id,
                    'name' => $account->name,
                    'legacy_slot' => $account->legacy_slot,
                    'amount' => 0.0,
                    'deposit_record_id' => null,
                ];
            }
        }

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
                'debts_collected' => (float) ($metrics['debts_collected'] ?? 0),
                'total_collected' => (float) $metrics['total_collected'],
                'discount_given' => (float) $metrics['discount_given'],
                'credit_used_total' => (float) $metrics['credit_used_total'],
                'settlement_total' => (float) $metrics['settlement_total'],
                'uncollected_installments' => (float) $metrics['uncollected_installments'],
                'uncollected_debts' => (float) ($metrics['uncollected_debts'] ?? 0),
                'open_debt' => (float) ($metrics['open_debt'] ?? $metrics['uncollected_debts'] ?? 0),
            ],
            'accounts' => \App\Services\ShopSalesReportService::accountsBreakdown($metrics),
            'shop_accounts' => $shopAccounts->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'sort_order' => (int) $a->sort_order,
                'legacy_slot' => $a->legacy_slot,
                'balance' => round((float) ($balances[$a->id] ?? 0), 2),
            ])->values(),
            'account_deposits' => $accountDeposits,
            'deposit_cash' => $recon ? (float) $recon->deposit_cash : 0.0,
            'reconciliation' => $recon,
        ], 200);
    }

    /**
     * ثبت / ویرایش واریز روز.
     * POST /api/daily-reconciliations
     *
     * فرمت جدید:
     * {
     *   "date": "2026-08-26",
     *   "deposit_cash": 0,
     *   "account_deposits": [
     *     {"shop_account_id": 1, "amount": 1000000},
     *     {"shop_account_id": 2, "amount": 500000}
     *   ]
     * }
     *
     * فرمت قدیمی (سازگار):
     * { "date", "deposit_account_1", "deposit_account_2", "deposit_cash" }
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت تطبیق روزانه فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $hasAccountDeposits = $request->has('account_deposits');

        $rules = [
            'date' => 'required|date',
            'deposit_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ];

        if ($hasAccountDeposits) {
            $rules['account_deposits'] = 'required|array|min:1';
            $rules['account_deposits.*.shop_account_id'] = 'required|integer|exists:shop_accounts,id';
            $rules['account_deposits.*.amount'] = 'required|numeric|min:0';
        } else {
            $rules['deposit_account_1'] = 'required|numeric|min:0';
            $rules['deposit_account_2'] = 'required|numeric|min:0';
        }

        $fields = $request->validate($rules);

        $user = $this->requireStaffShopUser($request);
        $dateKey = Carbon::parse($fields['date'])->setTimezone('Asia/Tehran')->format('Y-m-d');

        $payload = [
            'deposit_cash' => $fields['deposit_cash'],
        ];
        if ($hasAccountDeposits) {
            $payload['account_deposits'] = $fields['account_deposits'];
        } else {
            $payload['deposit_account_1'] = $fields['deposit_account_1'];
            $payload['deposit_account_2'] = $fields['deposit_account_2'];
        }

        try {
            $recon = DailyShopReconciliationService::upsert(
                $atelierId,
                $dateKey,
                $payload,
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
            'shop_accounts' => $grid['shop_accounts'] ?? [],
        ], 201);
    }

    protected function parseDateKey(Request $request, string $date): string
    {
        $request->merge(['route_date' => $date]);
        $request->validate(['route_date' => 'date']);

        return Carbon::parse($date)->setTimezone('Asia/Tehran')->format('Y-m-d');
    }
}
