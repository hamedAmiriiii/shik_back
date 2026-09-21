<?php

namespace App\Services\SmartCustomer;

use App\Models\ShopCampaign;
use App\Models\ShopCustomerMetric;
use App\Models\ShopCustomerSegment;
use App\Models\ShopSmartAction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SmartDashboardService
{
    public const SPARKLINE_DAYS = 14;

    /**
     * @return array<string, mixed>
     */
    public static function overview(int $atelierId): array
    {
        $labels = ShopCustomerSegment::labels();
        $counts = ShopCustomerSegment::query()
            ->where('atelier_id', $atelierId)
            ->select('primary_segment', DB::raw('COUNT(*) as c'))
            ->groupBy('primary_segment')
            ->pluck('c', 'primary_segment')
            ->map(fn ($c) => (int) $c)
            ->all();

        $nearVip = (int) ShopCustomerSegment::query()
            ->where('atelier_id', $atelierId)
            ->whereJsonContains('tags', ShopCustomerSegment::TAG_NEAR_VIP)
            ->count();
        $ready = (int) ShopCustomerSegment::query()
            ->where('atelier_id', $atelierId)
            ->whereJsonContains('tags', ShopCustomerSegment::TAG_READY_REPURCHASE)
            ->count();

        $total = (int) array_sum($counts);
        $window = Schema::hasTable('shop_segment_thresholds')
            ? (string) ShopSegmentThresholdService::forAtelier($atelierId)->metrics_window
            : 'all';

        $segmentSeries = self::segmentActivitySeries($atelierId);
        $readySeries = self::tagActivitySeries($atelierId, ShopCustomerSegment::TAG_READY_REPURCHASE);
        $nearVipSeries = self::tagActivitySeries($atelierId, ShopCustomerSegment::TAG_NEAR_VIP);

        $featured = [
            self::card('vip', 'VIP', (int) ($counts[ShopCustomerSegment::VIP] ?? 0), $total, $segmentSeries[ShopCustomerSegment::VIP] ?? self::emptySeries(), 'segment', ShopCustomerSegment::VIP),
            self::card('ready_repurchase', 'آماده خرید مجدد', $ready, $total, $readySeries, 'tag', ShopCustomerSegment::TAG_READY_REPURCHASE),
            self::card('at_risk', 'در معرض ریزش', (int) ($counts[ShopCustomerSegment::AT_RISK] ?? 0), $total, $segmentSeries[ShopCustomerSegment::AT_RISK] ?? self::emptySeries(), 'segment', ShopCustomerSegment::AT_RISK),
            self::card('loyal', 'وفادار', (int) ($counts[ShopCustomerSegment::LOYAL] ?? 0), $total, $segmentSeries[ShopCustomerSegment::LOYAL] ?? self::emptySeries(), 'segment', ShopCustomerSegment::LOYAL),
        ];

        $extra = [
            self::card('near_vip', 'نزدیک به VIP', $nearVip, $total, $nearVipSeries, 'tag', ShopCustomerSegment::TAG_NEAR_VIP),
            self::card('growing', $labels[ShopCustomerSegment::GROWING], (int) ($counts[ShopCustomerSegment::GROWING] ?? 0), $total, $segmentSeries[ShopCustomerSegment::GROWING] ?? self::emptySeries(), 'segment', ShopCustomerSegment::GROWING),
            self::card('new', $labels[ShopCustomerSegment::NEW], (int) ($counts[ShopCustomerSegment::NEW] ?? 0), $total, $segmentSeries[ShopCustomerSegment::NEW] ?? self::emptySeries(), 'segment', ShopCustomerSegment::NEW),
            self::card('churned', $labels[ShopCustomerSegment::CHURNED], (int) ($counts[ShopCustomerSegment::CHURNED] ?? 0), $total, $segmentSeries[ShopCustomerSegment::CHURNED] ?? self::emptySeries(), 'segment', ShopCustomerSegment::CHURNED),
            self::card('inactive', $labels[ShopCustomerSegment::INACTIVE], (int) ($counts[ShopCustomerSegment::INACTIVE] ?? 0), $total, $segmentSeries[ShopCustomerSegment::INACTIVE] ?? self::emptySeries(), 'segment', ShopCustomerSegment::INACTIVE),
            self::card('other', $labels[ShopCustomerSegment::OTHER], (int) ($counts[ShopCustomerSegment::OTHER] ?? 0), $total, $segmentSeries[ShopCustomerSegment::OTHER] ?? self::emptySeries(), 'segment', ShopCustomerSegment::OTHER),
        ];

        $suggestions = self::suggestions($atelierId, $ready, $nearVip);
        $headline = [];
        $atRisk = (int) ($counts[ShopCustomerSegment::AT_RISK] ?? 0);
        if ($atRisk > 0) {
            $headline[] = ['tone' => 'danger', 'text' => "{$atRisk} مشتری در معرض ریزش"];
        }
        if ($ready > 0) {
            $headline[] = ['tone' => 'warning', 'text' => "{$ready} مشتری آماده خرید مجدد"];
        }
        if ($nearVip > 0) {
            $headline[] = ['tone' => 'success', 'text' => "{$nearVip} مشتری به VIP نزدیک شده‌اند"];
        }

        $actionCount = 0;
        if (Schema::hasTable('shop_smart_actions')) {
            $actionCount = (int) ShopSmartAction::query()
                ->where('atelier_id', $atelierId)
                ->where('status', ShopSmartAction::STATUS_SUGGESTED)
                ->count();
        }
        $campaignCount = Schema::hasTable('shop_campaigns')
            ? (int) ShopCampaign::query()->where('atelier_id', $atelierId)->count()
            : 0;

        $ai = self::aiSuggestion($headline, $suggestions, $actionCount, $atRisk);

        $lastComputed = Schema::hasTable('shop_customer_metrics')
            ? ShopCustomerMetric::query()->where('atelier_id', $atelierId)->max('computed_at')
            : null;

        return [
            'ready' => true,
            'last_computed_at' => $lastComputed,
            'window_label' => self::windowLabel($window),
            'segment_labels' => $labels,
            'counts' => $counts,
            'tag_counts' => [
                'near_vip' => $nearVip,
                'ready_repurchase' => $ready,
            ],
            'headline' => $headline,
            'suggestions' => $suggestions,
            'total_customers' => $total,
            'featured' => $featured,
            'extra_cards' => $extra,
            'distribution' => self::distribution($counts, $labels, $total),
            'rfm' => self::rfmAverages($atelierId),
            'ai_suggestion' => $ai,
            'ops' => [
                [
                    'key' => 'actions',
                    'title' => 'پیشنهاد اقدام',
                    'count' => $actionCount,
                    'href' => '/admin/smart-club/actions',
                ],
                [
                    'key' => 'campaigns',
                    'title' => 'کمپین‌ها',
                    'count' => $campaignCount,
                    'href' => '/admin/smart-club/campaigns',
                ],
                [
                    'key' => 'customers',
                    'title' => 'مشتریان RFM',
                    'count' => $total,
                    'href' => '/admin/smart-club/customers',
                ],
                [
                    'key' => 'thresholds',
                    'title' => 'آستانه‌ها',
                    'count' => null,
                    'href' => '/admin/smart-club/thresholds',
                ],
            ],
        ];
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, string> $labels
     * @return array<int, array{key:string,label:string,count:int,percent:float}>
     */
    private static function distribution(array $counts, array $labels, int $total): array
    {
        $order = [
            ShopCustomerSegment::VIP,
            ShopCustomerSegment::LOYAL,
            ShopCustomerSegment::AT_RISK,
            ShopCustomerSegment::GROWING,
            ShopCustomerSegment::NEW,
            ShopCustomerSegment::CHURNED,
            ShopCustomerSegment::INACTIVE,
            ShopCustomerSegment::OTHER,
        ];
        $out = [];
        foreach ($order as $key) {
            $count = (int) ($counts[$key] ?? 0);
            $always = in_array($key, [ShopCustomerSegment::VIP, ShopCustomerSegment::LOYAL, ShopCustomerSegment::AT_RISK], true);
            if ($count <= 0 && ! $always) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'count' => $count,
                'percent' => $total > 0 ? round($count * 100 / $total, 1) : 0,
            ];
        }

        return $out;
    }

    /**
     * @return array{R:float,F:float,M:float,overall:float}
     */
    private static function rfmAverages(int $atelierId): array
    {
        $rSum = $fSum = $mSum = $n = 0;
        ShopCustomerSegment::query()
            ->where('atelier_id', $atelierId)
            ->select(['id', 'rfm_scores'])
            ->orderBy('id')
            ->chunkById(400, function ($rows) use (&$rSum, &$fSum, &$mSum, &$n) {
                foreach ($rows as $row) {
                    $scores = $row->rfm_scores;
                    if (is_string($scores)) {
                        $scores = json_decode($scores, true);
                    }
                    if (! is_array($scores)) {
                        continue;
                    }
                    $r = (int) ($scores['R'] ?? $scores['r'] ?? 0);
                    $f = (int) ($scores['F'] ?? $scores['f'] ?? 0);
                    $m = (int) ($scores['M'] ?? $scores['m'] ?? 0);
                    if ($r + $f + $m <= 0) {
                        continue;
                    }
                    $n++;
                    $rSum += $r;
                    $fSum += $f;
                    $mSum += $m;
                }
            });

        if ($n === 0) {
            return ['R' => 0, 'F' => 0, 'M' => 0, 'overall' => 0];
        }

        $r = round($rSum / $n, 1);
        $f = round($fSum / $n, 1);
        $m = round($mSum / $n, 1);

        return [
            'R' => $r,
            'F' => $f,
            'M' => $m,
            'overall' => round(($r + $f + $m) / 3, 1),
        ];
    }

    /**
     * @param array<int, array{tone:string,text:string}> $headline
     * @param array<int, array<string, mixed>> $suggestions
     * @return array{title:string,body:string,href:string}|null
     */
    private static function aiSuggestion(array $headline, array $suggestions, int $actionCount, int $atRisk): ?array
    {
        if ($atRisk > 0) {
            return [
                'title' => 'پیشنهاد هوشمند',
                'body' => 'مشتریان در معرض ریزش را با پیشنهاد ویژه بازگردانید.',
                'href' => '/admin/smart-club/actions',
            ];
        }
        if ($headline !== []) {
            return [
                'title' => 'پیشنهاد هوشمند',
                'body' => $headline[0]['text'],
                'href' => '/admin/smart-club/actions',
            ];
        }
        if ($suggestions !== []) {
            return [
                'title' => 'پیشنهاد هوشمند',
                'body' => $suggestions[0]['title'].' — '.$suggestions[0]['count'].' مشتری',
                'href' => '/admin/smart-club/actions',
            ];
        }
        if ($actionCount > 0) {
            return [
                'title' => 'پیشنهاد هوشمند',
                'body' => "{$actionCount} پیشنهاد اقدام آماده بررسی است.",
                'href' => '/admin/smart-club/actions',
            ];
        }

        return [
            'title' => 'پیشنهاد هوشمند',
            'body' => 'برای پیشنهادهای تازه، «محاسبه دوباره» را بزنید.',
            'href' => '/admin/smart-club/actions',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function suggestions(int $atelierId, int $ready, int $nearVip): array
    {
        if (! Schema::hasTable('shop_smart_actions')) {
            return [];
        }

        $out = [];
        $winback = ShopSmartAction::query()
            ->where('atelier_id', $atelierId)
            ->where('status', ShopSmartAction::STATUS_SUGGESTED)
            ->where('action_type', ShopSmartAction::TYPE_WINBACK)
            ->get();
        if ($winback->isNotEmpty()) {
            $out[] = [
                'key' => 'winback',
                'title' => 'کمپین بازگشت مشتری',
                'count' => $winback->count(),
                'estimated_revenue' => round((float) $winback->sum('estimated_revenue'), 0),
                'severity' => 'danger',
            ];
        }
        $readyActions = ShopSmartAction::query()
            ->where('atelier_id', $atelierId)
            ->where('status', ShopSmartAction::STATUS_SUGGESTED)
            ->where('action_type', ShopSmartAction::TYPE_READY_REPURCHASE)
            ->count();
        if ($readyActions > 0) {
            $out[] = [
                'key' => 'ready_repurchase',
                'title' => 'آماده خرید مجدد',
                'count' => $readyActions,
                'estimated_revenue' => null,
                'severity' => 'warning',
            ];
        } elseif ($ready > 0) {
            $out[] = [
                'key' => 'ready_repurchase',
                'title' => 'آماده خرید مجدد',
                'count' => $ready,
                'estimated_revenue' => null,
                'severity' => 'warning',
            ];
        }
        $nearActions = ShopSmartAction::query()
            ->where('atelier_id', $atelierId)
            ->where('status', ShopSmartAction::STATUS_SUGGESTED)
            ->where('action_type', ShopSmartAction::TYPE_NEAR_VIP)
            ->count();
        if ($nearActions > 0) {
            $out[] = [
                'key' => 'near_vip',
                'title' => 'نزدیک به VIP',
                'count' => $nearActions,
                'estimated_revenue' => null,
                'severity' => 'success',
            ];
        } elseif ($nearVip > 0) {
            $out[] = [
                'key' => 'near_vip',
                'title' => 'نزدیک به VIP',
                'count' => $nearVip,
                'estimated_revenue' => null,
                'severity' => 'success',
            ];
        }

        return $out;
    }

    /**
     * @param array{points:array<int,int>,trend_pct:?int} $series
     * @return array<string, mixed>
     */
    private static function card(
        string $key,
        string $title,
        int $count,
        int $total,
        array $series,
        string $filterType,
        string $filterValue,
    ): array {
        $href = $filterType === 'tag'
            ? '/admin/smart-club/customers?tag='.$filterValue
            : '/admin/smart-club/customers?segment='.$filterValue;

        return [
            'key' => $key,
            'title' => $title,
            'count' => $count,
            'percent' => $total > 0 ? round($count * 100 / $total, 1) : 0,
            'trend_pct' => $series['trend_pct'],
            'sparkline' => $series['points'],
            'href' => $href,
        ];
    }

    /**
     * @return array<string, array{points:array<int,int>,trend_pct:?int}>
     */
    private static function segmentActivitySeries(int $atelierId): array
    {
        if (! Schema::hasTable('purchases') || ! Schema::hasTable('shop_customer_segments')) {
            return [];
        }
        $from = Carbon::now('Asia/Tehran')->startOfDay()->subDays(self::SPARKLINE_DAYS - 1);
        $rows = DB::table('purchases as p')
            ->join('shop_customer_segments as s', function ($join) {
                $join->on('s.atelier_id', '=', 'p.atelier_id')
                    ->on('s.phone', '=', 'p.phone');
            })
            ->where('p.atelier_id', $atelierId)
            ->where('p.total_amount', '>', 0)
            ->where('p.created_at', '>=', $from)
            ->whereNotNull('p.phone')
            ->where('p.phone', '!=', '')
            ->selectRaw('s.primary_segment as seg, DATE(p.created_at) as d, COUNT(*) as c')
            ->groupBy('seg', 'd')
            ->get();

        $bySeg = [];
        foreach ($rows as $row) {
            $bySeg[(string) $row->seg][(string) $row->d] = (int) $row->c;
        }

        $out = [];
        foreach ($bySeg as $seg => $days) {
            $out[$seg] = self::pointsFromMap($days, $from);
        }

        return $out;
    }

    /**
     * @return array{points:array<int,int>,trend_pct:?int}
     */
    private static function tagActivitySeries(int $atelierId, string $tag): array
    {
        if (! Schema::hasTable('purchases') || ! Schema::hasTable('shop_customer_segments')) {
            return self::emptySeries();
        }
        $from = Carbon::now('Asia/Tehran')->startOfDay()->subDays(self::SPARKLINE_DAYS - 1);
        $rows = DB::table('purchases as p')
            ->join('shop_customer_segments as s', function ($join) {
                $join->on('s.atelier_id', '=', 'p.atelier_id')
                    ->on('s.phone', '=', 'p.phone');
            })
            ->where('p.atelier_id', $atelierId)
            ->where('p.total_amount', '>', 0)
            ->where('p.created_at', '>=', $from)
            ->whereJsonContains('s.tags', $tag)
            ->whereNotNull('p.phone')
            ->where('p.phone', '!=', '')
            ->selectRaw('DATE(p.created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd')
            ->all();

        return self::pointsFromMap($rows, $from);
    }

    /**
     * @param array<string, int> $map
     * @return array{points:array<int,int>,trend_pct:?int}
     */
    private static function pointsFromMap(array $map, Carbon $from): array
    {
        $points = [];
        for ($i = 0; $i < self::SPARKLINE_DAYS; $i++) {
            $d = $from->copy()->addDays($i)->toDateString();
            $points[] = (int) ($map[$d] ?? 0);
        }
        $recent = array_sum(array_slice($points, -7));
        $prev = array_sum(array_slice($points, 0, 7));
        $trend = null;
        if ($prev > 0) {
            $trend = (int) round((($recent - $prev) / $prev) * 100);
        } elseif ($recent > 0) {
            $trend = 100;
        }

        return ['points' => $points, 'trend_pct' => $trend];
    }

    /**
     * @return array{points:array<int,int>,trend_pct:?int}
     */
    private static function emptySeries(): array
    {
        return ['points' => array_fill(0, self::SPARKLINE_DAYS, 0), 'trend_pct' => null];
    }

    private static function windowLabel(string $window): string
    {
        if ($window === 'all' || $window === '') {
            return 'کل دوره';
        }
        $days = (int) $window;
        if ($days <= 0) {
            return 'کل دوره';
        }

        return $days.' روز گذشته';
    }
}
