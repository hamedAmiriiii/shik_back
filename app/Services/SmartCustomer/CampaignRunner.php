<?php

namespace App\Services\SmartCustomer;

use App\Exceptions\InsufficientShopSmsQuotaException;
use App\Models\ShopCampaign;
use App\Models\ShopCampaignAction;
use App\Models\ShopCampaignLog;
use App\Models\ShopCampaignRun;
use App\Models\ShopCustomerMetric;
use App\Models\ShopCustomerSegment;
use App\Models\UserShiksho;
use App\Services\ShopSmsQuotaService;
use App\Services\UserCreditGrantService;
use App\Tools\PriceTools;
use App\Tools\SmsTools;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CampaignRunner
{
    public static function tableReady(): bool
    {
        return Schema::hasTable('shop_campaigns')
            && Schema::hasTable('shop_campaign_logs')
            && Schema::hasTable('shop_customer_metrics');
    }

    /**
     * اجرای دستی کمپین — فاز ۱ فقط از داشبورد.
     *
     * @return array<string, mixed>
     */
    public static function runManual(ShopCampaign $campaign): array
    {
        if (! self::tableReady()) {
            return ['ok' => false, 'message' => 'جداول باشگاه هوشمند آماده نیست.'];
        }

        if ($campaign->status !== ShopCampaign::STATUS_ACTIVE) {
            return ['ok' => false, 'message' => 'فقط کمپین فعال قابل اجراست.'];
        }

        $atelierId = (int) $campaign->atelier_id;
        $campaign->load(['rule', 'actions']);
        $conditions = $campaign->rule->conditions ?? null;
        if (! is_array($conditions)) {
            return ['ok' => false, 'message' => 'قوانین کمپین تعریف نشده است.'];
        }

        $matches = [];
        $metrics = ShopCustomerMetric::query()->where('atelier_id', $atelierId)->get();
        $segments = ShopCustomerSegment::query()
            ->where('atelier_id', $atelierId)
            ->get()
            ->keyBy('phone');

        foreach ($metrics as $metric) {
            $seg = $segments->get($metric->phone);
            if (CampaignRuleEvaluator::matches($conditions, $metric, $seg)) {
                $matches[] = $metric;
            }
        }

        $max = $campaign->max_recipients_per_run;
        if ($max !== null && (int) $max > 0) {
            $matches = array_slice($matches, 0, (int) $max);
        }

        $needsSms = $campaign->actions->contains(function (ShopCampaignAction $a) {
            return $a->type === ShopCampaignAction::TYPE_SEND_SMS;
        });

        if ($needsSms && count($matches) > 0) {
            $sampleSms = 'پیام کمپین';
            foreach ($campaign->actions as $action) {
                if ($action->type === ShopCampaignAction::TYPE_SEND_SMS) {
                    $sampleSms = (string) (($action->config['message'] ?? '') ?: 'پیام کمپین');
                    break;
                }
            }
            $partsEach = ShopSmsQuotaService::countSmsParts($sampleSms);
            $needed = $partsEach * count($matches);
            $balance = ShopSmsQuotaService::getBalance($atelierId);
            if ($balance < $needed) {
                return [
                    'ok' => false,
                    'message' => (new InsufficientShopSmsQuotaException($needed, $balance))->getMessage(),
                ];
            }
        }

        $run = ShopCampaignRun::create([
            'campaign_id' => $campaign->id,
            'atelier_id' => $atelierId,
            'trigger' => 'manual',
            'matched_count' => count($matches),
            'status' => 'running',
        ]);

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $estRevenue = 0.0;
        $cooldownDays = max(1, (int) $campaign->cooldown_days);
        $since = Carbon::now('Asia/Tehran')->subDays($cooldownDays);

        foreach ($matches as $metric) {
            $phone = (string) $metric->phone;

            $recent = ShopCampaignLog::query()
                ->where('campaign_id', $campaign->id)
                ->where('phone', $phone)
                ->where('status', 'sent')
                ->where('created_at', '>=', $since)
                ->exists();

            if ($recent) {
                ShopCampaignLog::create([
                    'campaign_id' => $campaign->id,
                    'run_id' => $run->id,
                    'atelier_id' => $atelierId,
                    'phone' => $phone,
                    'status' => 'skipped',
                    'skip_reason' => 'cooldown',
                ]);
                $skipped++;
                continue;
            }

            try {
                $result = DB::transaction(function () use ($campaign, $atelierId, $phone, $metric) {
                    return self::applyActions($campaign, $atelierId, $phone, $metric);
                });

                ShopCampaignLog::create([
                    'campaign_id' => $campaign->id,
                    'run_id' => $run->id,
                    'atelier_id' => $atelierId,
                    'phone' => $phone,
                    'status' => 'sent',
                    'actions_result' => $result,
                ]);
                $sent++;
                $estRevenue += (float) $metric->avg_order_value * 0.35;
            } catch (\Throwable $e) {
                ShopCampaignLog::create([
                    'campaign_id' => $campaign->id,
                    'run_id' => $run->id,
                    'atelier_id' => $atelierId,
                    'phone' => $phone,
                    'status' => 'failed',
                    'skip_reason' => 'error',
                    'actions_result' => ['error' => $e->getMessage()],
                ]);
                $failed++;
            }
        }

        $run->update([
            'sent_count' => $sent,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'estimated_revenue' => round($estRevenue, 0),
            'status' => 'completed',
        ]);

        return [
            'ok' => true,
            'run_id' => $run->id,
            'matched' => count($matches),
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
            'estimated_revenue' => round($estRevenue, 0),
        ];
    }

    /**
     * پیش‌نمایش تعداد match بدون اجرا.
     *
     * @return array{matched:int,estimated_revenue:float,sample_phones:array<int,string>}
     */
    public static function preview(ShopCampaign $campaign): array
    {
        $atelierId = (int) $campaign->atelier_id;
        $campaign->loadMissing(['rule']);
        $conditions = $campaign->rule->conditions ?? null;
        if (! is_array($conditions)) {
            return ['matched' => 0, 'estimated_revenue' => 0, 'sample_phones' => []];
        }

        $matches = [];
        $est = 0.0;
        $segments = ShopCustomerSegment::query()
            ->where('atelier_id', $atelierId)
            ->get()
            ->keyBy('phone');

        foreach (ShopCustomerMetric::query()->where('atelier_id', $atelierId)->cursor() as $metric) {
            $seg = $segments->get($metric->phone);
            if (CampaignRuleEvaluator::matches($conditions, $metric, $seg)) {
                $matches[] = $metric->phone;
                $est += (float) $metric->avg_order_value * 0.35;
            }
        }

        $max = $campaign->max_recipients_per_run;
        if ($max !== null && (int) $max > 0 && count($matches) > (int) $max) {
            $matches = array_slice($matches, 0, (int) $max);
        }

        return [
            'matched' => count($matches),
            'estimated_revenue' => round($est, 0),
            'sample_phones' => array_slice($matches, 0, 20),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function applyActions(
        ShopCampaign $campaign,
        int $atelierId,
        string $phone,
        ShopCustomerMetric $metric
    ): array {
        $results = [];
        foreach ($campaign->actions as $action) {
            $config = $action->config ?? [];
            if ($action->type === ShopCampaignAction::TYPE_GRANT_CREDIT) {
                $amount = round((float) ($config['amount'] ?? 0), 2);
                if ($amount >= 0.01) {
                    $results['credit'] = self::addCredit($atelierId, $phone, $amount);
                }
            } elseif ($action->type === ShopCampaignAction::TYPE_SEND_SMS) {
                $message = trim((string) ($config['message'] ?? ''));
                if ($message === '') {
                    $message = 'پیام باشگاه مشتریان';
                }
                // replace simple tokens
                $message = str_replace(
                    ['{phone}', '{recency}', '{credit}'],
                    [$phone, (string) $metric->recency_days, (string) ($results['credit']['added'] ?? '')],
                    $message
                );
                SmsTools::sendShopSms($phone, $message, null, null, 'campaign', $atelierId);
                $results['sms'] = true;
            }
        }

        return $results;
    }

    /**
     * اعتبار کمپین روی موجودی فعلی جمع می‌شود (add)، نه replace خرید عادی.
     *
     * @return array{old:float,new:float,added:float}
     */
    public static function addCredit(int $atelierId, string $phone, float $amount): array
    {
        $user = UserShiksho::firstOrCreate(
            ['phone' => $phone, 'atelier_id' => $atelierId],
            [
                'credit' => 0,
                'installment_credit' => 0,
                'credit_last_updated_at' => now(),
                'last_warning_sent_at' => null,
            ]
        );

        $old = (float) $user->credit;
        $added = PriceTools::roundToThousand($amount);
        $new = PriceTools::roundToThousand($old + $added);
        $user->credit = $new;
        $user->credit_last_updated_at = now();
        $user->last_warning_sent_at = null;
        $user->save();

        UserCreditGrantService::recordManualChange(
            $atelierId,
            $phone,
            'regular',
            $old,
            $new
        );

        return ['old' => $old, 'new' => $new, 'added' => $added];
    }
}
