<?php

namespace App\Http\Controllers\Repair;

use App\Http\Controllers\Controller;
use App\Models\GatewayPayment;
use App\Models\RepairRequest;
use App\Models\RepairService;
use App\Models\RepairSetting;
use App\Models\RepairUser;
use App\Services\GatewayPaymentService;
use App\Services\Repair\RepairRequestService;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RepairCustomerController extends Controller
{
    public function __construct(protected RepairRequestService $service)
    {
    }

    public function index(Request $request)
    {
        $customer = $this->customer($request);
        $rows = RepairRequest::query()
            ->with(['technician'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (RepairRequest $r) => $r->toApiArray('customer'))
            ->all();

        return response(['requests' => $rows]);
    }

    public function store(Request $request)
    {
        $customer = $this->customer($request);
        $locationMode = RepairSetting::locationMode();
        $locationRule = $locationMode === 'required' ? 'required' : 'nullable';
        $data = $request->validate([
            'service_id' => 'nullable|integer',
            'description' => 'required|string|max:3000',
            'address' => 'required|string|max:1000',
            'latitude' => $locationRule.'|numeric|between:24,40|required_with:longitude',
            'longitude' => $locationRule.'|numeric|between:44,64|required_with:latitude',
            'contact_name' => 'nullable|string|max:150',
            'contact_phone' => 'nullable|string|max:20',
            'preferred_time' => 'nullable|string|max:255',
        ], [
            'latitude.required' => 'موقعیت را روی نقشه انتخاب کنید.',
            'longitude.required' => 'موقعیت را روی نقشه انتخاب کنید.',
            'latitude.between' => 'موقعیت انتخاب‌شده خارج از ایران است.',
            'longitude.between' => 'موقعیت انتخاب‌شده خارج از ایران است.',
        ]);
        if ($locationMode === 'off') {
            unset($data['latitude'], $data['longitude']);
        }

        $data['service_id'] = null;
        $data['category'] = null;
        if (! Schema::hasTable('repair_services')) {
            $category = trim((string) $request->input('category', ''));
            $data['category'] = in_array($category, RepairSetting::categories(), true) ? $category : null;
        } elseif ($request->filled('service_id')) {
            $service = RepairService::query()->where('is_active', true)->find((int) $request->input('service_id'));
            if (! $service) {
                return response()->json(['message' => 'نوع خدمت انتخاب‌شده معتبر نیست.'], 422);
            }
            $data['service_id'] = (int) $service->id;
            $data['category'] = $service->name;
        } elseif (RepairService::query()->where('is_active', true)->exists()) {
            return response()->json(['message' => 'نوع خدمت را انتخاب کنید.'], 422);
        }
        if (! empty($data['contact_phone'])) {
            $phone = PhoneTools::normalizeIranPhone($data['contact_phone']);
            if (! PhoneTools::isValidIranMobile($phone)) {
                return response()->json(['message' => 'شمارهٔ تماس معتبر نیست.'], 422);
            }
            $data['contact_phone'] = $phone;
        }

        $openCount = RepairRequest::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [RepairRequest::STATUS_PENDING, RepairRequest::STATUS_ASSIGNED])
            ->count();
        if ($openCount >= 5) {
            return response()->json(['message' => 'تعداد درخواست‌های باز شما زیاد است. منتظر رسیدگی بمانید.'], 422);
        }

        $repair = $this->service->create($customer, $data);

        return response([
            'message' => 'درخواست شما ثبت شد. به‌زودی تعمیرکار برایتان ارجاع می‌شود.',
            'request' => $repair->fresh(['technician'])->toApiArray('customer'),
        ], 201);
    }

    public function show(Request $request, RepairRequest $repairRequest)
    {
        $this->assertOwner($request, $repairRequest);
        $repairRequest->load(['technician']);

        return response([
            'request' => $repairRequest->toApiArray('customer'),
            'payment' => $this->paymentOptions(),
        ]);
    }

    public function cancel(Request $request, RepairRequest $repairRequest)
    {
        $this->assertOwner($request, $repairRequest);
        if (! in_array($repairRequest->status, [RepairRequest::STATUS_PENDING, RepairRequest::STATUS_ASSIGNED], true)) {
            return response()->json(['message' => 'پس از شروع کار امکان لغو از طرف شما نیست؛ با پشتیبانی تماس بگیرید.'], 422);
        }
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $repair = $this->service->cancel($repairRequest, $data['reason'] ?? 'لغو توسط مشتری');

        return response(['message' => 'درخواست لغو شد.', 'request' => $repair->toApiArray('customer')]);
    }

    public function payOnline(Request $request, RepairRequest $repairRequest, GatewayPaymentService $payments)
    {
        $customer = $this->customer($request);
        $this->assertOwner($request, $repairRequest);
        if (RepairSetting::value('online_payment_enabled') !== '1') {
            return response()->json(['message' => 'پرداخت آنلاین فعال نیست.'], 422);
        }
        $data = $request->validate([
            'return_url' => 'nullable|string|max:1024',
            'gateway' => 'nullable|string|in:'.implode(',', GatewayPayment::gateways()),
        ]);

        try {
            $payload = $payments->start(
                0,
                null,
                GatewayPayment::TYPE_REPAIR_INVOICE,
                (int) $repairRequest->id,
                $data['return_url'] ?? null,
                $customer->phone,
                $data['gateway'] ?? GatewayPayment::GATEWAY_ZARINPAL
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'payment_url' => $payload['payment_url'],
            'authority' => $payload['authority'],
        ], 201);
    }

    public function uploadReceipt(Request $request, RepairRequest $repairRequest)
    {
        $this->assertOwner($request, $repairRequest);
        if (RepairSetting::value('card_payment_enabled') !== '1') {
            return response()->json(['message' => 'پرداخت کارت به کارت فعال نیست.'], 422);
        }
        $request->validate([
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
            'receipt_base64' => 'nullable|string',
            'payment_ref' => 'nullable|string|max:100',
        ]);

        try {
            [$content, $ext] = RepairRequestService::readReceiptUpload(
                $request->file('receipt'),
                $request->input('receipt_base64')
            );
            $repair = $this->service->submitReceipt($repairRequest, $content, $ext, $request->input('payment_ref'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'رسید ثبت شد و پس از بررسی تأیید می‌شود.',
            'request' => $repair->toApiArray('customer'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentOptions(): array
    {
        $values = RepairSetting::allValues();

        return [
            'online_enabled' => $values['online_payment_enabled'] === '1',
            'card_enabled' => $values['card_payment_enabled'] === '1' && $values['card_number'] !== '',
            'card_number' => $values['card_number'],
            'card_holder' => $values['card_holder'],
            'bank_name' => $values['bank_name'],
        ];
    }

    private function customer(Request $request): RepairUser
    {
        /** @var RepairUser $user */
        $user = $request->user();

        return $user;
    }

    private function assertOwner(Request $request, RepairRequest $repairRequest): void
    {
        if ((int) $repairRequest->customer_id !== (int) $this->customer($request)->id) {
            abort(response()->json(['message' => 'درخواست یافت نشد.'], 404));
        }
    }
}
