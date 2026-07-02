<?php

namespace App\Http\Controllers;

use App\Models\SmsPackage;
use App\Models\SmsPackageOrder;
use App\Services\ShopSmsQuotaService;
use App\Services\SmsPackageOrderService;
use Illuminate\Http\Request;

class ShopSmsPackageController extends Controller
{
    /**
     * لیست بسته‌های پیامکی فعال.
     */
    public function index(Request $request)
    {
        $this->requireStaffShopUser($request);

        $packages = SmsPackage::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (SmsPackage $p) => $this->formatPackage($p));

        return response([
            'data' => $packages,
            'chars_per_sms' => ShopSmsQuotaService::CHARS_PER_SMS_PART,
        ], 200);
    }

    /**
     * ثبت درخواست خرید بسته پیامکی.
     */
    public function purchase(Request $request, SmsPackage $smsPackage)
    {
        $user = $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $order = SmsPackageOrderService::createOrder($atelierId, $smsPackage, $user->id);

        return response([
            'message' => 'درخواست خرید با موفقیت ثبت شد. پس از تأیید ادمین، اعتبار پیامک شما شارژ می‌شود.',
            'order' => $this->formatOrder($order),
        ], 201);
    }

    /**
     * تاریخچه درخواست‌های خرید بسته پیامکی فروشگاه.
     */
    public function orders(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $query = SmsPackageOrder::query()
            ->with(['smsPackage', 'requestedBy', 'reviewedBy'])
            ->where('atelier_id', $atelierId)
            ->orderByDesc('id');

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel && is_object($searchDataModel) && isset($searchDataModel->status)) {
            $query->where('status', $searchDataModel->status);
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        $paginator = $query->paginate($perPage);
        $paginator->withPath(url()->current());
        $paginator->getCollection()->transform(fn (SmsPackageOrder $order) => $this->formatOrder($order));

        return response($paginator, 200);
    }

    protected function formatPackage(SmsPackage $package): array
    {
        return [
            'id' => $package->id,
            'name' => $package->name,
            'sms_count' => $package->sms_count,
            'price_rial' => $package->price_rial,
            'price_toman' => $package->price_rial !== null ? (int) ($package->price_rial / 10) : null,
            'sort_order' => $package->sort_order,
        ];
    }

    protected function formatOrder(SmsPackageOrder $order): array
    {
        $order->loadMissing(['smsPackage', 'requestedBy', 'reviewedBy']);

        return [
            'id' => $order->id,
            'atelier_id' => $order->atelier_id,
            'sms_package_id' => $order->sms_package_id,
            'package_name' => $order->smsPackage ? $order->smsPackage->name : null,
            'sms_count' => $order->sms_count,
            'price_rial' => $order->price_rial,
            'price_toman' => $order->price_rial !== null ? (int) ($order->price_rial / 10) : null,
            'status' => $order->status,
            'status_label' => $this->statusLabel($order->status),
            'admin_note' => $order->admin_note,
            'requested_by' => $order->requestedBy ? [
                'id' => $order->requestedBy->id,
                'name' => trim($order->requestedBy->name.' '.$order->requestedBy->last_name),
                'phone' => $order->requestedBy->phone,
            ] : null,
            'reviewed_by' => $order->reviewedBy ? [
                'id' => $order->reviewedBy->id,
                'name' => trim($order->reviewedBy->name.' '.$order->reviewedBy->last_name),
            ] : null,
            'reviewed_at' => $order->reviewed_at,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
        ];
    }

    protected function statusLabel(string $status): string
    {
        return [
            SmsPackageOrder::STATUS_PENDING => 'در انتظار تأیید',
            SmsPackageOrder::STATUS_APPROVED => 'تأیید شده',
            SmsPackageOrder::STATUS_REJECTED => 'رد شده',
        ][$status] ?? $status;
    }
}
