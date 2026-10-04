<?php

namespace App\Http\Controllers\Repair;

use App\Http\Controllers\Controller;
use App\Models\GatewayPayment;
use App\Models\RepairSmsLog;
use App\Models\ShopSmsLog;
use App\Models\SmsPackage;
use App\Services\GatewayPaymentService;
use App\Services\Repair\RepairSms;
use App\Services\ShopSmsQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RepairSmsController extends Controller
{
    public function __construct(protected RepairSms $sms)
    {
    }

    public function summary(GatewayPaymentService $payments)
    {
        $enabled = RepairSms::enabled();
        $packages = Schema::hasTable('sms_packages')
            ? SmsPackage::query()->active()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (SmsPackage $p) => $payments->formatSmsPackage($p))
                ->values()
            : [];

        return response([
            'enabled' => $enabled,
            'balance' => $this->sms->balance(),
            'chars_per_sms' => ShopSmsQuotaService::CHARS_PER_SMS_PART,
            'sent_today' => $enabled ? RepairSmsLog::query()->whereDate('created_at', today())->count() : 0,
            'used_this_month' => $enabled
                ? (int) RepairSmsLog::query()->where('created_at', '>=', now()->startOfMonth())->sum('sms_parts')
                : 0,
            'packages' => $packages,
            'gateways' => [
                ['id' => GatewayPayment::GATEWAY_ZARINPAL, 'name' => 'زرین‌پال'],
                ['id' => GatewayPayment::GATEWAY_SEP, 'name' => 'سامان کیش (SEP)'],
            ],
            'default_gateway' => GatewayPayment::GATEWAY_ZARINPAL,
            'types' => collect(RepairSmsLog::TYPE_LABELS)
                ->map(fn ($label, $id) => ['id' => $id, 'label' => $label])
                ->values(),
        ]);
    }

    public function logs(Request $request)
    {
        if (! RepairSms::enabled()) {
            return response(['logs' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]);
        }

        $query = RepairSmsLog::query()->latest('id');
        $type = $request->query('type');
        if (is_string($type) && isset(RepairSmsLog::TYPE_LABELS[$type])) {
            $query->where('sms_type', $type);
        }
        $status = $request->query('status');
        if ($status === 'failed') {
            $query->whereIn('delivery_status', array_merge(
                array_diff(ShopSmsLog::FINAL_STATUSES, [ShopSmsLog::STATUS_DELIVERED]),
                [RepairSmsLog::STATUS_NO_CREDIT]
            ));
        } elseif (is_string($status) && $status !== '') {
            $query->where('delivery_status', strtoupper($status));
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', strtr($q, [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            ]));
            $query->where(function ($w) use ($q, $digits) {
                $w->where('message', 'like', '%'.$q.'%');
                if ($digits !== '') {
                    $w->orWhere('phone', 'like', '%'.$digits.'%');
                }
            });
        }

        $page = $query->paginate(20);

        return response([
            'logs' => collect($page->items())->map(fn (RepairSmsLog $log) => $log->toApiArray())->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function refreshStatus(RepairSmsLog $smsLog)
    {
        return response(['log' => $this->sms->refreshStatus($smsLog)->toApiArray()]);
    }

    public function refreshPending()
    {
        if (! RepairSms::enabled()) {
            return response(['message' => 'جدول پیامک‌ها ساخته نشده است.', 'updated' => 0]);
        }

        $rows = RepairSmsLog::query()
            ->where(function ($w) {
                $w->whereNull('delivery_status')
                    ->orWhereNotIn('delivery_status', array_merge(ShopSmsLog::FINAL_STATUSES, [RepairSmsLog::STATUS_NO_CREDIT]));
            })
            ->where(fn ($w) => $w->whereNotNull('reference_id')->orWhereNotNull('batch_id'))
            ->where('created_at', '>=', now()->subDays(7))
            ->latest('id')
            ->limit(50)
            ->get();

        foreach ($rows as $row) {
            $this->sms->refreshStatus($row);
        }

        return response(['message' => 'وضعیت '.count($rows).' پیامک به‌روز شد.', 'updated' => count($rows)]);
    }

    public function purchase(Request $request, GatewayPaymentService $payments)
    {
        if (! RepairSms::enabled()) {
            return response()->json(['message' => 'ابتدا SQL پنل پیامک تعمیرات را اجرا کنید.'], 422);
        }
        $data = $request->validate([
            'package_id' => 'required|integer',
            'return_url' => 'nullable|string|max:1024',
            'gateway' => 'nullable|string|in:'.implode(',', GatewayPayment::gateways()),
        ]);

        try {
            $payload = $payments->start(
                0,
                null,
                GatewayPayment::TYPE_REPAIR_SMS,
                (int) $data['package_id'],
                $data['return_url'] ?? null,
                $request->user()->phone ?? null,
                $data['gateway'] ?? GatewayPayment::GATEWAY_ZARINPAL
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'payment_url' => $payload['payment_url'],
            'authority' => $payload['authority'] ?? null,
        ], 201);
    }

    public function orders()
    {
        if (! Schema::hasTable('gateway_payments')) {
            return response(['orders' => []]);
        }

        $orders = GatewayPayment::query()
            ->where('type', GatewayPayment::TYPE_REPAIR_SMS)
            ->whereIn('status', [GatewayPayment::STATUS_PAID, GatewayPayment::STATUS_FAILED])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (GatewayPayment $p) => [
                'id' => $p->id,
                'name' => $p->meta['name'] ?? $p->description,
                'sms_count' => (int) ($p->meta['sms_count'] ?? 0),
                'amount_toman' => (int) floor(((int) $p->amount_rial) / 10),
                'status' => $p->status,
                'gateway' => $p->gateway,
                'ref_id' => $p->ref_id,
                'created_at' => $p->created_at?->toIso8601String(),
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])
            ->values();

        return response(['orders' => $orders]);
    }
}
