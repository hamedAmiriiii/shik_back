<?php

namespace App\Services\SmartCustomer;

use App\Models\ShopCustomerMetric;
use App\Models\ShopCustomerSegment;
use App\Models\ShopSmartAction;
use App\Models\UserShiksho;
use App\Tools\PriceTools;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class SmartActionGenerator
{
    public static function tableReady(): bool
    {
        return Schema::hasTable('shop_smart_actions')
            && Schema::hasTable('shop_customer_segments')
            && Schema::hasTable('shop_customer_metrics');
    }

    /**
     * @return array{created:int,expired:int}
     */
    public static function generateForAtelier(int $atelierId): array
    {
        if (! self::tableReady() || $atelierId <= 0) {
            return ['created' => 0, 'expired' => 0];
        }

        $thresholds = ShopSegmentThresholdService::forAtelier($atelierId);
        $cooldownDays = max(1, (int) $thresholds->action_cooldown_days);
        $now = Carbon::now('Asia/Tehran');
        $expiresAt = $now->copy()->addHours(36);
        $suggestedSendAt = $now->copy()->setTime(19, 0, 0);
        if ($suggestedSendAt->lt($now)) {
            $suggestedSendAt->addDay();
        }

        $expired = ShopSmartAction::query()
            ->where('atelier_id', $atelierId)
            ->where('status', ShopSmartAction::STATUS_SUGGESTED)
            ->where(function ($q) use ($now) {
                $q->whereNotNull('expires_at')->where('expires_at', '<', $now);
            })
            ->update(['status' => ShopSmartAction::STATUS_EXPIRED]);

        $created = 0;

        $segments = ShopCustomerSegment::query()
            ->where('atelier_id', $atelierId)
            ->get()
            ->filter(function (ShopCustomerSegment $seg) {
                $tags = $seg->tags ?? [];

                return in_array($seg->primary_segment, [
                    ShopCustomerSegment::AT_RISK,
                    ShopCustomerSegment::INACTIVE,
                ], true)
                    || in_array(ShopCustomerSegment::TAG_NEAR_VIP, $tags, true)
                    || in_array(ShopCustomerSegment::TAG_READY_REPURCHASE, $tags, true);
            });

        $names = self::customerNames($atelierId, $segments->pluck('phone')->all());

        foreach ($segments as $seg) {
            $metric = ShopCustomerMetric::query()
                ->where('atelier_id', $atelierId)
                ->where('phone', $seg->phone)
                ->first();
            if (! $metric) {
                continue;
            }

            $actions = self::buildActionsFor($seg, $metric, $thresholds, $suggestedSendAt, $names[$seg->phone] ?? null);
            foreach ($actions as $action) {
                if (self::inCooldown($atelierId, $seg->phone, $action['action_type'], $cooldownDays, $now)) {
                    continue;
                }

                ShopSmartAction::create([
                    'atelier_id' => $atelierId,
                    'phone' => $seg->phone,
                    'action_type' => $action['action_type'],
                    'priority' => $action['priority'],
                    'title' => $action['title'],
                    'reason' => $action['reason'],
                    'payload' => $action['payload'],
                    'estimated_revenue' => $action['estimated_revenue'],
                    'status' => ShopSmartAction::STATUS_SUGGESTED,
                    'source' => 'system',
                    'suggested_send_at' => $suggestedSendAt,
                    'expires_at' => $expiresAt,
                ]);
                $created++;
            }
        }

        return ['created' => $created, 'expired' => (int) $expired];
    }

    /**
     * @param  array<int, string>  $phones
     * @return array<string, string>
     */
    public static function customerNames(int $atelierId, array $phones): array
    {
        $phones = array_values(array_unique(array_filter(array_map('strval', $phones))));
        if ($phones === [] || ! Schema::hasColumn('user_shiksho', 'name')) {
            return [];
        }

        $names = [];
        foreach (array_chunk($phones, 500) as $chunk) {
            UserShiksho::query()
                ->where('atelier_id', $atelierId)
                ->whereIn('phone', $chunk)
                ->whereNotNull('name')
                ->get(['phone', 'name'])
                ->each(function (UserShiksho $u) use (&$names) {
                    $name = trim((string) $u->name);
                    if ($name !== '') {
                        $names[$u->phone] = $name;
                    }
                });
        }

        return $names;
    }

    public static function customerName(int $atelierId, string $phone): ?string
    {
        return self::customerNames($atelierId, [$phone])[$phone] ?? null;
    }

    /** خطاب پیامک: «علی عزیز» یا در نبود نام «مشتری گرامی» — هرگز شماره موبایل. */
    public static function greeting(?string $name): string
    {
        $name = trim((string) $name);

        return $name !== '' ? "{$name} عزیز" : 'مشتری گرامی';
    }

    /**
     * آماده‌سازی متن پیشنهاد پیش از ارسال: توکن‌ها جایگزین می‌شوند و
     * پیشنهادهای قدیمی که به‌جای نام، شماره موبایل داشتند اصلاح می‌شوند.
     */
    public static function renderTemplate(string $template, int $atelierId, string $phone, ?float $credit = null): string
    {
        $name = self::customerName($atelierId, $phone);
        $greeting = self::greeting($name);

        $text = str_replace(["{$phone} عزیز", $phone], [$greeting, $greeting], $template);

        return str_replace(
            ['{greeting}', '{name}', '{credit}'],
            [$greeting, $name ?? 'مشتری', $credit !== null ? number_format($credit) : ''],
            $text
        );
    }

    protected static function inCooldown(
        int $atelierId,
        string $phone,
        string $actionType,
        int $cooldownDays,
        Carbon $now
    ): bool {
        $since = $now->copy()->subDays($cooldownDays);

        return ShopSmartAction::query()
            ->where('atelier_id', $atelierId)
            ->where('phone', $phone)
            ->where('action_type', $actionType)
            ->where('created_at', '>=', $since)
            ->whereIn('status', [
                ShopSmartAction::STATUS_SUGGESTED,
                ShopSmartAction::STATUS_ACCEPTED,
                ShopSmartAction::STATUS_EXECUTED,
            ])
            ->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function buildActionsFor(
        ShopCustomerSegment $seg,
        ShopCustomerMetric $metric,
        $thresholds,
        Carbon $suggestedSendAt,
        ?string $customerName = null
    ): array {
        $out = [];
        $credit = (float) $thresholds->winback_credit_amount;
        $factor = (float) $thresholds->winback_revenue_factor;
        $est = round((float) $metric->avg_order_value * $factor, 0);
        $avg = $metric->avg_days_between !== null ? (float) $metric->avg_days_between : null;
        $greeting = self::greeting($customerName);
        $winbackCredit = PriceTools::roundToThousand($credit);
        $nearVipCredit = PriceTools::roundToThousand(round($credit * 0.5, 0));

        if (in_array($seg->primary_segment, [ShopCustomerSegment::AT_RISK, ShopCustomerSegment::INACTIVE], true)) {
            $avgText = $avg !== null ? number_format($avg, 0) . ' روز' : 'نامشخص';
            $out[] = [
                'action_type' => ShopSmartAction::TYPE_WINBACK,
                'priority' => $seg->primary_segment === ShopCustomerSegment::AT_RISK ? 90 : 70,
                'title' => 'کمپین بازگشت مشتری',
                'reason' => sprintf(
                    'آخرین خرید: %d روز قبل — میانگین فاصله خرید: %s',
                    (int) $metric->recency_days,
                    $avgText
                ),
                'payload' => [
                    'credit' => $credit,
                    'sms' => true,
                    'template' => $winbackCredit >= 1
                        ? "{$greeting}، دلمان برایتان تنگ شده! " . number_format($winbackCredit) . ' تومان اعتبار هدیه در فروشگاه منتظر شماست.'
                        : "{$greeting}، دلمان برایتان تنگ شده! منتظر بازگشت شما هستیم.",
                    'suggested_send_at' => $suggestedSendAt->toDateTimeString(),
                ],
                'estimated_revenue' => $est,
            ];
        }

        $tags = $seg->tags ?? [];
        if (in_array(ShopCustomerSegment::TAG_NEAR_VIP, $tags, true)) {
            $out[] = [
                'action_type' => ShopSmartAction::TYPE_NEAR_VIP,
                'priority' => 60,
                'title' => 'نزدیک به VIP',
                'reason' => sprintf(
                    'با %d خرید و مبلغ %s تومان نزدیک سطح VIP است',
                    (int) $metric->frequency,
                    number_format((float) $metric->monetary, 0)
                ),
                'payload' => [
                    'credit' => round($credit * 0.5, 0),
                    'sms' => true,
                    'template' => "{$greeting}، یک قدم تا عضویت VIP مانده‌اید — با خرید بعدی وارد باشگاه ویژه شوید."
                        . ($nearVipCredit >= 1 ? ' ' . number_format($nearVipCredit) . ' تومان اعتبار هدیه هم برایتان شارژ شد.' : ''),
                ],
                'estimated_revenue' => round($est * 1.2, 0),
            ];
        }

        if (in_array(ShopCustomerSegment::TAG_READY_REPURCHASE, $tags, true)
            && $seg->primary_segment !== ShopCustomerSegment::AT_RISK
        ) {
            $out[] = [
                'action_type' => ShopSmartAction::TYPE_READY_REPURCHASE,
                'priority' => 55,
                'title' => 'آماده خرید مجدد',
                'reason' => sprintf(
                    'فاصله از آخرین خرید (%d روز) نزدیک میانگین خرید مشتری است',
                    (int) $metric->recency_days
                ),
                'payload' => [
                    'credit' => 0,
                    'sms' => true,
                    'template' => "{$greeting}، زمان خرید بعدی‌تان نزدیک است — منتظر دیدنتان هستیم.",
                ],
                'estimated_revenue' => $est,
            ];
        }

        return $out;
    }
}
