<?php

namespace App\Services;

use App\Models\ShopAccount;
use App\Models\ShopPartner;
use App\Models\ShopPartnerSettlement;
use App\Models\ShopPartnerSettlementLine;
use App\Tools\PriceTools;
use Carbon\Carbon;
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
     * پیش‌نمایش سود قابل تقسیم از فردای آخرین تسویه تا امروز (یا تاریخ to).
     *
     * @return array<string, mixed>
     */
    public static function profitPreview(int $atelierId, ?string $toDate = null): array
    {
        $to = self::normalizeDate($toDate) ?: Carbon::now('Asia/Tehran')->toDateString();
        $period = self::currentPeriod($atelierId, $to);
        $pnl = AccountingReportService::profitLoss($atelierId, $period['from'], $period['to']);
        $netProfit = round((float) ($pnl['net_profit'] ?? 0), 2);

        $partners = ShopPartner::query()->forAtelier($atelierId)->active()->orderBy('id')->get();
        $totalCapital = (float) $partners->sum('capital_amount');
        $shares = self::allocateShares($partners->all(), $netProfit, $totalCapital);

        return [
            'period_from' => $period['from'],
            'period_to' => $period['to'],
            'period_from_jalali' => $pnl['from'] ?? null,
            'period_to_jalali' => $pnl['to'] ?? null,
            'last_settlement_at' => $period['last_settlement_at'],
            'last_settlement_at_jalali' => $period['last_settlement_at_jalali'],
            'net_profit' => $netProfit,
            'total_capital' => round($totalCapital, 2),
            'can_settle' => $netProfit >= 0.01 && $totalCapital >= 0.01 && $partners->isNotEmpty(),
            'partners' => $shares,
            'pnl' => $pnl,
        ];
    }

    /**
     * @param  array{shop_account_id: int, to?: string|null, notes?: string|null, user_name?: string|null}  $input
     */
    public static function settle(int $atelierId, array $input): ShopPartnerSettlement
    {
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

        $preview = self::profitPreview($atelierId, $input['to'] ?? null);
        if (! $preview['can_settle']) {
            if ((float) $preview['net_profit'] < 0.01) {
                throw new InvalidArgumentException('سود خالص دوره برای تقسیم کافی نیست.');
            }
            throw new InvalidArgumentException('برای تقسیم سود، حداقل یک شریک فعال با سرمایه لازم است.');
        }

        $total = round((float) $preview['net_profit'], 2);
        $available = ShopAccountBalanceService::availableBalance($account);
        if ($available + 0.001 < $total) {
            throw new InvalidArgumentException(
                'موجودی حساب کافی نیست. موجودی: '.number_format($available, 0).' تومان'
            );
        }

        return DB::transaction(function () use ($atelierId, $input, $preview, $shopAccountId, $total) {
            $settlement = ShopPartnerSettlement::create([
                'atelier_id' => $atelierId,
                'settled_at' => Carbon::now('Asia/Tehran')->toDateString(),
                'period_from' => $preview['period_from'],
                'period_to' => $preview['period_to'],
                'net_profit' => $total,
                'total_distributed' => $total,
                'shop_account_id' => $shopAccountId,
                'user_name' => $input['user_name'] ?? null,
                'notes' => $input['notes'] ?? null,
            ]);

            foreach ($preview['partners'] as $row) {
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
            }

            AccountingPartnerSettlementPoster::post($settlement->fresh(['lines', 'shopAccount']));

            return $settlement->fresh(['lines', 'shopAccount']);
        });
    }

    /**
     * @return array{from: ?string, to: string, last_settlement_at: ?string, last_settlement_at_jalali: ?string}
     */
    public static function currentPeriod(int $atelierId, string $toDate): array
    {
        $last = null;
        if (ShopPartnerSettlement::tableReady()) {
            $last = ShopPartnerSettlement::query()
                ->where('atelier_id', $atelierId)
                ->orderByDesc('period_to')
                ->orderByDesc('id')
                ->first();
        }

        $from = null;
        $lastAt = null;
        $lastJalali = null;
        if ($last) {
            $lastAt = optional($last->period_to)->toDateString() ?: (string) $last->getRawOriginal('period_to');
            $lastJalali = $last->period_to_jalali;
            $from = Carbon::parse($lastAt, 'Asia/Tehran')->addDay()->toDateString();
            if ($from > $toDate) {
                // هنوز روز جدیدی برای تقسیم نیست؛ بازه صفرروزه
                $from = $toDate;
            }
        }

        return [
            'from' => $from,
            'to' => $toDate,
            'last_settlement_at' => $lastAt,
            'last_settlement_at_jalali' => $lastJalali,
        ];
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
