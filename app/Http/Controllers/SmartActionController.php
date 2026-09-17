<?php

namespace App\Http\Controllers;

use App\Models\ShopSmartAction;
use App\Models\UserShiksho;
use App\Services\SmartCustomer\CampaignRunner;
use App\Services\ShopFeatureFlags;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SmartActionController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );

        if (! Schema::hasTable('shop_smart_actions')) {
            return response(['actions' => []], 200);
        }

        $status = $request->query('status', ShopSmartAction::STATUS_SUGGESTED);

        $actions = ShopSmartAction::query()
            ->where('atelier_id', $atelierId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $phones = $actions->pluck('phone')->unique()->values();
        $names = UserShiksho::query()
            ->where('atelier_id', $atelierId)
            ->whereIn('phone', $phones)
            ->pluck('name', 'phone');

        $mapped = $actions->map(function (ShopSmartAction $a) use ($names) {
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

        return response(['actions' => $mapped], 200);
    }

    public function dismiss(Request $request, $id)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );

        $action = ShopSmartAction::query()
            ->where('atelier_id', $atelierId)
            ->where('id', (int) $id)
            ->firstOrFail();

        $action->status = ShopSmartAction::STATUS_DISMISSED;
        $action->save();

        return response(['message' => 'پیشنهاد رد شد.', 'action' => $action], 200);
    }

    /**
     * پذیرش و اجرای فوری پیشنهاد سیستم (اعتبار + SMS در صورت وجود).
     */
    public function accept(Request $request, $id)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );

        $action = ShopSmartAction::query()
            ->where('atelier_id', $atelierId)
            ->where('id', (int) $id)
            ->firstOrFail();

        if ($action->status !== ShopSmartAction::STATUS_SUGGESTED) {
            return response(['message' => 'این پیشنهاد قابل اجرا نیست.'], 422);
        }

        $payload = $action->payload ?? [];
        $credit = round((float) ($payload['credit'] ?? 0), 2);
        $sendSms = (bool) ($payload['sms'] ?? false);
        $template = trim((string) ($payload['template'] ?? ''));
        $result = [];

        if ($credit >= 0.01) {
            $result['credit'] = CampaignRunner::addCredit($atelierId, $action->phone, $credit);
            if ($template !== '') {
                $template = str_replace('{credit}', (string) ($result['credit']['added'] ?? $credit), $template);
            }
        }

        if ($sendSms && $template !== '') {
            try {
                SmsTools::sendShopSms($action->phone, $template, null, $credit > 0 ? $credit : null, 'campaign', $atelierId);
                $result['sms'] = true;
            } catch (\App\Exceptions\InsufficientShopSmsQuotaException $e) {
                return response(['message' => $e->getMessage(), 'partial' => $result], 422);
            }
        }

        $action->status = ShopSmartAction::STATUS_EXECUTED;
        $action->save();

        return response([
            'message' => 'اقدام اجرا شد.',
            'action' => $action,
            'result' => $result,
        ], 200);
    }
}
