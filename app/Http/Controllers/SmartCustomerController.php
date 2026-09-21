<?php

namespace App\Http\Controllers;

use App\Models\ShopCustomerMetric;
use App\Models\ShopCustomerSegment;
use App\Models\ShopSmartAction;
use App\Models\UserShiksho;
use App\Services\SmartCustomer\CampaignRunner;
use App\Services\SmartCustomer\ShopSegmentThresholdService;
use App\Services\SmartCustomer\SmartCustomerPipeline;
use App\Services\SmartCustomer\SmartDashboardService;
use App\Services\ShopFeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SmartCustomerController extends Controller
{
    public function overview(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );

        if (! Schema::hasTable('shop_customer_segments')) {
            return response([
                'ready' => false,
                'message' => 'جداول باشگاه هوشمند هنوز ساخته نشده‌اند.',
                'counts' => [],
                'suggestions' => [],
            ], 200);
        }

        return response(SmartDashboardService::overview($atelierId), 200);
    }

    public function customers(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );

        if (! Schema::hasTable('shop_customer_metrics')) {
            return response(['data' => [], 'total' => 0], 200);
        }

        $segment = $request->query('segment');
        $tag = $request->query('tag');
        $search = trim((string) $request->query('search', ''));
        $perPage = min(100, max(10, (int) $request->query('per_page', 30)));

        $q = ShopCustomerMetric::query()
            ->from('shop_customer_metrics as m')
            ->leftJoin('shop_customer_segments as s', function ($join) {
                $join->on('s.atelier_id', '=', 'm.atelier_id')
                    ->on('s.phone', '=', 'm.phone');
            })
            ->leftJoin('user_shiksho as u', function ($join) {
                $join->on('u.atelier_id', '=', 'm.atelier_id')
                    ->on('u.phone', '=', 'm.phone');
            })
            ->where('m.atelier_id', $atelierId)
            ->select([
                'm.phone',
                'm.recency_days',
                'm.frequency',
                'm.monetary',
                'm.avg_days_between',
                'm.avg_order_value',
                'm.last_purchase_at',
                's.primary_segment',
                's.tags',
                's.rfm_scores',
                'u.name',
            ]);

        if ($segment) {
            $q->where('s.primary_segment', $segment);
        }
        if ($tag) {
            $q->whereJsonContains('s.tags', $tag);
        }
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('m.phone', 'like', "%{$search}%")
                    ->orWhere('u.name', 'like', "%{$search}%");
            });
        }

        $paginator = $q->orderByDesc('m.monetary')->paginate($perPage);

        $items = collect($paginator->items())->map(function ($row) {
            $tags = $row->tags;
            if (is_string($tags)) {
                $tags = json_decode($tags, true) ?: [];
            }
            $rfm = $row->rfm_scores;
            if (is_string($rfm)) {
                $rfm = json_decode($rfm, true) ?: [];
            }

            return [
                'phone' => $row->phone,
                'name' => $row->name,
                'recency_days' => (int) $row->recency_days,
                'frequency' => (int) $row->frequency,
                'monetary' => (float) $row->monetary,
                'avg_days_between' => $row->avg_days_between !== null ? (float) $row->avg_days_between : null,
                'avg_order_value' => (float) $row->avg_order_value,
                'last_purchase_at' => $row->last_purchase_at,
                'primary_segment' => $row->primary_segment,
                'segment_label' => ShopCustomerSegment::labels()[$row->primary_segment] ?? $row->primary_segment,
                'tags' => $tags ?? [],
                'rfm_scores' => $rfm ?? [],
            ];
        })->values();

        return response([
            'data' => $items,
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'segment_labels' => ShopCustomerSegment::labels(),
        ], 200);
    }

    public function thresholds(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );

        if (! ShopSegmentThresholdService::tableReady()) {
            return response(['message' => 'جدول آستانه‌ها وجود ندارد.'], 503);
        }

        $row = ShopSegmentThresholdService::forAtelier($atelierId);

        return response(['thresholds' => $row], 200);
    }

    public function updateThresholds(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );

        $validated = $request->validate([
            'metrics_window' => 'nullable|string|in:all,90,180,365',
            'vip_max_recency_days' => 'nullable|integer|min:1|max:365',
            'vip_min_frequency' => 'nullable|integer|min:1|max:1000',
            'vip_min_monetary' => 'nullable|numeric|min:0',
            'loyal_max_recency_days' => 'nullable|integer|min:1|max:365',
            'loyal_min_frequency' => 'nullable|integer|min:1|max:1000',
            'new_max_days_since_first' => 'nullable|integer|min:1|max:365',
            'new_max_frequency' => 'nullable|integer|min:1|max:100',
            'growing_min_purchases_90d' => 'nullable|integer|min:1|max:1000',
            'at_risk_recency_multiplier' => 'nullable|numeric|min:1|max:5',
            'at_risk_min_recency_days' => 'nullable|integer|min:1|max:365',
            'at_risk_min_frequency' => 'nullable|integer|min:1|max:1000',
            'inactive_min_recency_days' => 'nullable|integer|min:1|max:730',
            'churned_min_recency_days' => 'nullable|integer|min:1|max:1460',
            'high_value_min_monetary' => 'nullable|numeric|min:0',
            'low_value_max_monetary' => 'nullable|numeric|min:0',
            'near_vip_frequency_gap' => 'nullable|integer|min:0|max:50',
            'action_cooldown_days' => 'nullable|integer|min:1|max:30',
            'winback_credit_amount' => 'nullable|numeric|min:0',
            'winback_revenue_factor' => 'nullable|numeric|min:0|max:2',
        ]);

        $row = ShopSegmentThresholdService::updateForAtelier($atelierId, $validated);

        return response([
            'message' => 'آستانه‌ها ذخیره شد.',
            'thresholds' => $row,
        ], 200);
    }

    public function recompute(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );

        $result = SmartCustomerPipeline::runForAtelier($atelierId);

        return response([
            'message' => ! empty($result['ok'])
                ? 'محاسبه RFM، سگمنت و پیشنهادها انجام شد.'
                : 'محاسبه انجام نشد.',
            'result' => $result,
        ], ! empty($result['ok']) ? 200 : 422);
    }

    public function productSignals(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );

        $type = $request->query('type', 'bad');
        if (! in_array($type, ['bad', 'good'], true)) {
            $type = 'bad';
        }

        $data = \App\Services\SmartCustomer\ProductCycleInsightService::analyze($atelierId, $type);

        return response($data, 200);
    }

    public function actions(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );

        if (! Schema::hasTable('shop_smart_actions')) {
            return response(['data' => []], 200);
        }

        $status = $request->query('status', ShopSmartAction::STATUS_SUGGESTED);
        $rows = ShopSmartAction::query()
            ->where('atelier_id', $atelierId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $phones = $rows->pluck('phone')->unique()->values()->all();
        $names = UserShiksho::query()
            ->where('atelier_id', $atelierId)
            ->whereIn('phone', $phones)
            ->pluck('name', 'phone');

        $data = $rows->map(function (ShopSmartAction $a) use ($names) {
            return [
                'id' => $a->id,
                'phone' => $a->phone,
                'name' => $names[$a->phone] ?? null,
                'action_type' => $a->action_type,
                'priority' => (int) $a->priority,
                'title' => $a->title,
                'reason' => $a->reason,
                'payload' => $a->payload,
                'estimated_revenue' => (float) $a->estimated_revenue,
                'status' => $a->status,
                'suggested_send_at' => optional($a->suggested_send_at)->toDateTimeString(),
                'expires_at' => optional($a->expires_at)->toDateTimeString(),
                'created_at' => optional($a->created_at)->toDateTimeString(),
            ];
        })->values();

        return response(['data' => $data], 200);
    }

    public function dismissAction(Request $request, int $action)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );

        $row = ShopSmartAction::query()
            ->where('atelier_id', $atelierId)
            ->where('id', $action)
            ->firstOrFail();

        $row->status = ShopSmartAction::STATUS_DISMISSED;
        $row->save();

        return response(['message' => 'پیشنهاد رد شد.', 'action' => $row], 200);
    }

    public function executeAction(Request $request, int $action)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );

        $row = ShopSmartAction::query()
            ->where('atelier_id', $atelierId)
            ->where('id', $action)
            ->where('status', ShopSmartAction::STATUS_SUGGESTED)
            ->firstOrFail();

        $payload = $row->payload ?? [];
        $results = [];

        $credit = round((float) ($payload['credit'] ?? 0), 2);
        if ($credit >= 0.01) {
            $results['credit'] = CampaignRunner::addCredit($atelierId, $row->phone, $credit);
        }

        if (! empty($payload['sms'])) {
            $message = trim((string) ($payload['template'] ?? 'پیام باشگاه مشتریان'));
            try {
                \App\Tools\SmsTools::sendShopSms(
                    $row->phone,
                    $message,
                    null,
                    $credit > 0 ? $credit : null,
                    'campaign',
                    $atelierId
                );
                $results['sms'] = true;
            } catch (\App\Exceptions\InsufficientShopSmsQuotaException $e) {
                return response(['message' => $e->getMessage()], 422);
            }
        }

        $row->status = ShopSmartAction::STATUS_EXECUTED;
        $row->save();

        return response([
            'message' => 'اقدام اجرا شد.',
            'action' => $row,
            'results' => $results,
        ], 200);
    }
}
