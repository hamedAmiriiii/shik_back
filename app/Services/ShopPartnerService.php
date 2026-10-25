<?php

namespace App\Services;

use App\Models\ShopAccount;
use App\Models\ShopPartner;
use App\Models\ShopPartnerActivityLog;
use App\Models\ShopPartnerSettlement;
use App\Models\ShopPartnerSettlementLine;
use App\Tools\PriceTools;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ShopPartnerService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listPartners(int $atelierId, bool $activeOnly = false): array
    {
        if (! ShopPartner::tableReady()) {
            return [];
        }

        $query = ShopPartner::query()->forAtelier($atelierId)->orderByDesc('id');
        if ($activeOnly) {
            $query->active();
        }
        $partners = $query->get();
        $totalCapital = (float) $partners->where('is_active', true)->sum('capital_amount');

        return $partners->map(function (ShopPartner $partner) use ($totalCapital) {
            return self::serializePartner($partner, $totalCapital);
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializePartner(ShopPartner $partner, ?float $totalActiveCapital = null): array
    {
        if ($totalActiveCapital === null) {
            $totalActiveCapital = (float) ShopPartner::query()
                ->forAtelier((int) $partner->atelier_id)
                ->active()
                ->sum('capital_amount');
        }

        $capital = (float) $partner->capital_amount;
        $percent = 0.0;
        if ($partner->is_active && $totalActiveCapital >= 0.01 && $capital >= 0.01) {
            $percent = round(($capital / $totalActiveCapital) * 100, 4);
        }

        return [
            'id' => (int) $partner->id,
            'atelier_id' => (int) $partner->atelier_id,
            'name' => (string) $partner->name,
            'phone' => $partner->phone,
            'capital_amount' => round($capital, 2),
            'share_percent' => $percent,
            'is_active' => (bool) $partner->is_active,
            'notes' => $partner->notes,
            'created_at' => $partner->created_at,
            'updated_at' => $partner->updated_at,
        ];
    }

    /**
     * اسنپ‌شات سرمایه و درصد سهم همه شرکا برای لاگ.
     *
     * @return array{total_active_capital: float, partners: array<int, array<string, mixed>>}
     */
    public static function shareSnapshot(int $atelierId): array
    {
        $partners = self::listPartners($atelierId);
        $total = 0.0;
        $rows = [];
        foreach ($partners as $p) {
            if (! empty($p['is_active'])) {
                $total = round($total + (float) $p['capital_amount'], 2);
            }
            $rows[] = [
                'id' => (int) $p['id'],
                'name' => (string) $p['name'],
                'capital_amount' => round((float) $p['capital_amount'], 2),
                'share_percent' => round((float) $p['share_percent'], 4),
                'is_active' => (bool) $p['is_active'],
            ];
        }

        return [
            'total_active_capital' => $total,
            'partners' => $rows,
        ];
    }

    /**
     * پیش‌نمایش سود قابل تقسیم از فردای آخرین دورهٔ کامل‌شده تا تاریخ to
     * (اگر دورهٔ ناقص باز باشد، همان بازه قفل می‌ماند تا همه شرکا پرداخت شوند).
     *
     * @return array<string, mixed>
     */
    public static function profitPreview(int $atelierId, ?string $toDate = null): array
    {
        $requestedTo = self::normalizeDate($toDate) ?: Carbon::now('Asia/Tehran')->toDateString();
        $period = self::currentPeriod($atelierId, $requestedTo);
        $end = Carbon::parse($period['to'], 'Asia/Tehran')->endOfDay();
        $start = $period['from']
            ? Carbon::parse($period['from'], 'Asia/Tehran')->startOfDay()
            : Carbon::parse('2000-01-01', 'Asia/Tehran')->startOfDay();

        $pos = ShopSalesReportService::posNetProfitForRange($atelierId, $start, $end);
        $netProfit = round((float) ($pos['net_profit'] ?? 0), 2);

        $partners = ShopPartner::query()->forAtelier($atelierId)->active()->orderBy('id')->get();
        $totalCapital = (float) $partners->sum('capital_amount');
        $shares = self::allocateShares($partners->all(), $netProfit, $totalCapital);

        $paidByPartner = self::paidAmountsByPartner(
            $atelierId,
            $period['from'],
            $period['to']
        );

        $remainingTotal = 0.0;
        foreach ($shares as &$row) {
            $gross = (float) $row['share_amount'];
            $paid = PriceTools::roundToman((float) ($paidByPartner[$row['id']] ?? 0));
            $remaining = PriceTools::roundToman(max(0, $gross - $paid));
            $row['gross_share_amount'] = $gross;
            $row['paid_amount'] = $paid;
            $row['share_amount'] = $remaining;
            $row['can_pay'] = $remaining >= 0.01;
            $remainingTotal = PriceTools::roundToman($remainingTotal + $remaining);
        }
        unset($row);

        return [
            'period_from' => $period['from'],
            'period_to' => $period['to'],
            'period_from_jalali' => $period['from']
                ? Jalalian::fromCarbon(Carbon::parse($period['from'], 'Asia/Tehran'))->format('Y-m-d')
                : null,
            'period_to_jalali' => Jalalian::fromCarbon($end)->format('Y-m-d'),
            'period_locked' => (bool) ($period['period_locked'] ?? false),
            'requested_to' => $requestedTo,
            'last_settlement_at' => $period['last_settlement_at'],
            'last_settlement_at_jalali' => $period['last_settlement_at_jalali'],
            'net_profit' => $netProfit,
            'remaining_to_distribute' => $remainingTotal,
            'total_capital' => round($totalCapital, 2),
            'can_settle' => $remainingTotal >= 0.01 && $totalCapital >= 0.01 && $partners->isNotEmpty(),
            'partners' => $shares,
            'pnl' => [
                'from' => $period['from']
                    ? Jalalian::fromCarbon(Carbon::parse($period['from'], 'Asia/Tehran'))->format('Y-m-d')
                    : null,
                'to' => Jalalian::fromCarbon($end)->format('Y-m-d'),
                'sales' => $pos['sales'],
                'gross_profit' => $pos['gross_profit'],
                'operating_expense' => $pos['total_expenses'],
                'net_profit' => $netProfit,
                'note' => 'سود خالص قابل تقسیم همان سود خالص گزارش سود و ضرر فروشگاه است.',
            ],
        ];
    }

    /**
     * پرداخت سهم همه شرکای باقی‌مانده در دوره.
     *
     * @param  array{shop_account_id: int, to?: string|null, notes?: string|null, user_name?: string|null}  $input
     */
    public static function settle(int $atelierId, array $input): ShopPartnerSettlement
    {
        $preview = self::profitPreview($atelierId, $input['to'] ?? null);
        $payableRows = array_values(array_filter(
            $preview['partners'],
            fn (array $row) => (float) ($row['share_amount'] ?? 0) >= 0.01
        ));
        if ($payableRows === []) {
            throw new InvalidArgumentException('مبلغ باقی‌مانده‌ای برای تقسیم وجود ندارد.');
        }

        $input['activity_action'] = ShopPartnerActivityLog::ACTION_SETTLEMENT_PAY_ALL;

        return self::createSettlement(
            $atelierId,
            $input,
            $preview,
            $payableRows,
            PriceTools::roundToman(array_sum(array_column($payableRows, 'share_amount')))
        );
    }

    /**
     * پرداخت سهم فقط یک شریک.
     *
     * @param  array{shop_account_id: int, to?: string|null, notes?: string|null, user_name?: string|null}  $input
     */
    public static function settlePartner(int $atelierId, int $partnerId, array $input): ShopPartnerSettlement
    {
        $preview = self::profitPreview($atelierId, $input['to'] ?? null);
        $row = null;
        foreach ($preview['partners'] as $candidate) {
            if ((int) $candidate['id'] === $partnerId) {
                $row = $candidate;
                break;
            }
        }
        if (! $row || (float) ($row['share_amount'] ?? 0) < 0.01) {
            throw new InvalidArgumentException('برای این شریک مبلغ قابل پرداختی در این دوره نیست.');
        }

        $notes = trim((string) ($input['notes'] ?? ''));
        $partnerNote = 'پرداخت سود '.$row['name'];
        $input['notes'] = $notes !== '' ? $notes.' — '.$partnerNote : $partnerNote;
        $input['activity_action'] = ShopPartnerActivityLog::ACTION_SETTLEMENT_PAY_ONE;
        $input['activity_partner_id'] = (int) $row['id'];

        return self::createSettlement(
            $atelierId,
            $input,
            $preview,
            [$row],
            PriceTools::roundToman((float) $row['share_amount'])
        );
    }

    /**
     * @param  array{shop_account_id: int, to?: string|null, notes?: string|null, user_name?: string|null}  $input
     * @param  array<string, mixed>  $preview
     * @param  array<int, array<string, mixed>>  $payableRows
     */
    protected static function createSettlement(
        int $atelierId,
        array $input,
        array $preview,
        array $payableRows,
        float $payTotal
    ): ShopPartnerSettlement {
        if (! ShopPartner::tableReady() || ! ShopPartnerSettlement::tableReady()) {
            throw new RuntimeException('جداول شرکا آماده نیست. اسکریپت SQL را اجرا کنید.');
        }

        $shopAccountId = (int) ($input['shop_account_id'] ?? 0);
        $account = ShopAccount::query()->find($shopAccountId);
        if (! $account || (int) $account->atelier_id !== $atelierId || ! $account->is_active) {
            throw new InvalidArgumentException('حساب برداشت معتبر نیست.');
        }
        if ($account->isTill()) {
            throw new InvalidArgumentException('برداشت تقسیم سود از صندوق نقد مجاز نیست؛ حساب بانکی یا تنخواه انتخاب کنید.');
        }

        $payTotal = PriceTools::roundToman($payTotal);
        if ($payTotal < 0.01) {
            throw new InvalidArgumentException('مبلغ پرداخت معتبر نیست.');
        }

        $available = ShopAccountBalanceService::availableBalance($account);
        if ($available + 0.001 < $payTotal) {
            throw new InvalidArgumentException(
                'موجودی حساب کافی نیست. موجودی: '.number_format($available, 0).' تومان'
            );
        }

        $netProfit = PriceTools::roundToman((float) ($preview['net_profit'] ?? 0));

        return DB::transaction(function () use ($atelierId, $input, $preview, $shopAccountId, $payableRows, $payTotal, $netProfit) {
            $settlement = ShopPartnerSettlement::create([
                'atelier_id' => $atelierId,
                'settled_at' => Carbon::now('Asia/Tehran')->toDateString(),
                'period_from' => $preview['period_from'],
                'period_to' => $preview['period_to'],
                'net_profit' => $netProfit,
                'total_distributed' => $payTotal,
                'shop_account_id' => $shopAccountId,
                'user_name' => $input['user_name'] ?? null,
                'notes' => $input['notes'] ?? null,
            ]);

            $linesPayload = [];
            foreach ($payableRows as $row) {
                if ((float) $row['share_amount'] < 0.01) {
                    continue;
                }
                ShopPartnerSettlementLine::create([
                    'settlement_id' => $settlement->id,
                    'partner_id' => $row['id'],
                    'partner_name' => $row['name'],
                    'capital_amount' => $row['capital_amount'],
                    'share_percent' => $row['share_percent'],
                    'amount' => $row['share_amount'],
                ]);
                $linesPayload[] = [
                    'partner_id' => (int) $row['id'],
                    'partner_name' => (string) $row['name'],
                    'capital_amount' => round((float) $row['capital_amount'], 2),
                    'share_percent' => round((float) $row['share_percent'], 4),
                    'amount' => round((float) $row['share_amount'], 2),
                ];
            }

            AccountingPartnerSettlementPoster::post($settlement->fresh(['lines', 'shopAccount']));

            $action = (string) ($input['activity_action'] ?? ShopPartnerActivityLog::ACTION_SETTLEMENT_PAY_ALL);
            $partnerNames = array_map(fn ($l) => $l['partner_name'], $linesPayload);
            $periodLabel = trim(
                ($preview['period_from_jalali'] ?? 'ابتدا').' تا '.($preview['period_to_jalali'] ?? '—')
            );
            $message = $action === ShopPartnerActivityLog::ACTION_SETTLEMENT_PAY_ONE
                ? ('پرداخت سود '.($partnerNames[0] ?? 'شریک').' — '.$periodLabel.' — '.number_format($payTotal, 0).' تومان')
                : ('پرداخت سود شرکا ('.implode('، ', $partnerNames).') — '.$periodLabel.' — '.number_format($payTotal, 0).' تومان');

            ShopPartnerActivityLog::record([
                'atelier_id' => $atelierId,
                'action' => $action,
                'user_id' => isset($input['user_id']) ? (int) $input['user_id'] : null,
                'user_name' => $input['user_name'] ?? null,
                'partner_id' => isset($input['activity_partner_id']) ? (int) $input['activity_partner_id'] : null,
                'settlement_id' => (int) $settlement->id,
                'message' => $message,
                'payload' => [
                    'settlement_id' => (int) $settlement->id,
                    'period_from' => $preview['period_from'] ?? null,
                    'period_to' => $preview['period_to'] ?? null,
                    'period_from_jalali' => $preview['period_from_jalali'] ?? null,
                    'period_to_jalali' => $preview['period_to_jalali'] ?? null,
                    'net_profit' => $netProfit,
                    'total_distributed' => $payTotal,
                    'shop_account_id' => $shopAccountId,
                    'notes' => $input['notes'] ?? null,
                    'lines' => $linesPayload,
                ],
            ]);

            return $settlement->fresh(['lines', 'shopAccount']);
        });
    }

    /**
     * @return array{from: ?string, to: string, last_settlement_at: ?string, last_settlement_at_jalali: ?string, period_locked: bool}
     */
    public static function currentPeriod(int $atelierId, string $toDate): array
    {
        $open = self::findOpenPeriodGroup($atelierId);
        $lastMeta = self::lastSettlementMeta($atelierId);

        if ($open) {
            return [
                'from' => $open['from'],
                'to' => $open['to'],
                'last_settlement_at' => $lastMeta['last_settlement_at'],
                'last_settlement_at_jalali' => $lastMeta['last_settlement_at_jalali'],
                'period_locked' => true,
            ];
        }

        $from = null;
        if ($lastMeta['last_settlement_at']) {
            $from = Carbon::parse($lastMeta['last_settlement_at'], 'Asia/Tehran')->addDay()->toDateString();
            if ($from > $toDate) {
                $from = $toDate;
            }
        }

        return [
            'from' => $from,
            'to' => $toDate,
            'last_settlement_at' => $lastMeta['last_settlement_at'],
            'last_settlement_at_jalali' => $lastMeta['last_settlement_at_jalali'],
            'period_locked' => false,
        ];
    }

    /**
     * @return array{from: ?string, to: string, net_profit: float, paid: float}|null
     */
    protected static function findOpenPeriodGroup(int $atelierId): ?array
    {
        if (! ShopPartnerSettlement::tableReady()) {
            return null;
        }

        $settlements = ShopPartnerSettlement::query()
            ->where('atelier_id', $atelierId)
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->get(['id', 'period_from', 'period_to', 'net_profit', 'total_distributed']);

        if ($settlements->isEmpty()) {
            return null;
        }

        /** @var array<string, array{from: ?string, to: string, net_profit: float, paid: float}> $groups */
        $groups = [];
        foreach ($settlements as $settlement) {
            $from = optional($settlement->period_from)->toDateString();
            $to = optional($settlement->period_to)->toDateString();
            if (! $to) {
                continue;
            }
            $key = ($from ?: '').'|'.$to;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'from' => $from,
                    'to' => $to,
                    'net_profit' => (float) $settlement->net_profit,
                    'paid' => 0.0,
                ];
            }
            $groups[$key]['paid'] = PriceTools::roundToman(
                $groups[$key]['paid'] + (float) $settlement->total_distributed
            );
            $groups[$key]['net_profit'] = max(
                $groups[$key]['net_profit'],
                (float) $settlement->net_profit
            );
        }

        uasort($groups, function (array $a, array $b) {
            return strcmp($b['to'], $a['to']);
        });

        $latest = reset($groups) ?: null;
        if (! $latest) {
            return null;
        }

        if ($latest['paid'] + 0.01 < $latest['net_profit']) {
            return $latest;
        }

        return null;
    }

    /**
     * @return array{last_settlement_at: ?string, last_settlement_at_jalali: ?string}
     */
    protected static function lastSettlementMeta(int $atelierId): array
    {
        if (! ShopPartnerSettlement::tableReady()) {
            return ['last_settlement_at' => null, 'last_settlement_at_jalali' => null];
        }

        $last = ShopPartnerSettlement::query()
            ->where('atelier_id', $atelierId)
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->first();

        if (! $last) {
            return ['last_settlement_at' => null, 'last_settlement_at_jalali' => null];
        }

        return [
            'last_settlement_at' => optional($last->period_to)->toDateString()
                ?: (string) $last->getRawOriginal('period_to'),
            'last_settlement_at_jalali' => $last->period_to_jalali,
        ];
    }

    /**
     * مبلغ پرداخت‌شده به هر شریک در یک بازه مشخص.
     *
     * @return array<int, float>
     */
    public static function paidAmountsByPartner(int $atelierId, ?string $periodFrom, string $periodTo): array
    {
        if (! ShopPartnerSettlement::tableReady()) {
            return [];
        }

        $query = ShopPartnerSettlement::query()
            ->where('atelier_id', $atelierId)
            ->whereDate('period_to', $periodTo)
            ->with('lines');

        if ($periodFrom) {
            $query->whereDate('period_from', $periodFrom);
        } else {
            $query->whereNull('period_from');
        }

        $paid = [];
        foreach ($query->get() as $settlement) {
            foreach ($settlement->lines as $line) {
                $partnerId = (int) ($line->partner_id ?? 0);
                if ($partnerId <= 0) {
                    continue;
                }
                $paid[$partnerId] = PriceTools::roundToman(
                    ($paid[$partnerId] ?? 0) + (float) $line->amount
                );
            }
        }

        return $paid;
    }

    /**
     * @param  array<int, ShopPartner>  $partners
     * @return array<int, array<string, mixed>>
     */
    public static function allocateShares(array $partners, float $netProfit, float $totalCapital): array
    {
        $netProfit = PriceTools::roundToman($netProfit);
        $rows = [];
        $allocated = 0.0;
        $active = array_values(array_filter($partners, function (ShopPartner $p) {
            return $p->is_active && (float) $p->capital_amount >= 0.01;
        }));

        foreach ($active as $index => $partner) {
            $capital = PriceTools::roundToman((float) $partner->capital_amount);
            $percent = $totalCapital >= 0.01
                ? round(($capital / $totalCapital) * 100, 4)
                : 0.0;
            if ($index === count($active) - 1) {
                $amount = PriceTools::roundToman(max(0, $netProfit - $allocated));
            } else {
                $amount = PriceTools::roundToman($netProfit * ($percent / 100));
                $allocated = PriceTools::roundToman($allocated + $amount);
            }
            $rows[] = [
                'id' => (int) $partner->id,
                'name' => (string) $partner->name,
                'phone' => $partner->phone,
                'capital_amount' => $capital,
                'share_percent' => $percent,
                'share_amount' => $amount,
            ];
        }

        return $rows;
    }

    protected static function normalizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value, 'Asia/Tehran')->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
