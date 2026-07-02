<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsPackageOrder;
use App\Models\User;
use App\Services\ShopSmsQuotaService;
use App\Services\SmsPackageOrderService;
use Illuminate\Http\Request;

class SmsPackageOrderController extends Controller
{
    /**
     * لیست درخواست‌های خرید بسته پیامکی (گرید ادمین).
     */
    public function index(Request $request)
    {
        $this->requirePlatformAdmin($request);

        $query = SmsPackageOrder::query()
            ->with(['smsPackage', 'atelier', 'requestedBy', 'reviewedBy'])
            ->orderByDesc('id');

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    if (isset($searchDataModel->status)) {
                        $q->where('status', $searchDataModel->status);
                    }
                    if (isset($searchDataModel->shop_name) || isset($searchDataModel->name)) {
                        $name = $searchDataModel->shop_name ?? $searchDataModel->name;
                        $q->whereHas('atelier', function ($aq) use ($name) {
                            $aq->where('name', 'like', '%'.$name.'%');
                        });
                    }
                    if (isset($searchDataModel->atelier_id)) {
                        $q->orWhere('atelier_id', (int) $searchDataModel->atelier_id);
                    }
                } elseif (is_string($searchDataModel) && $searchDataModel !== '') {
                    $q->whereHas('atelier', function ($aq) use ($searchDataModel) {
                        $aq->where('name', 'like', '%'.$searchDataModel.'%');
                    });
                }
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        $paginator = $query->paginate($perPage);
        $paginator->withPath(url()->current());

        $atelierIds = collect($paginator->items())
            ->pluck('atelier_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();
        $ownersByAtelier = $this->loadShopOwnersByAtelierIds($atelierIds);

        $paginator->getCollection()->transform(function (SmsPackageOrder $order) use ($ownersByAtelier) {
            return $this->formatOrder($order, $ownersByAtelier);
        });

        $payload = $paginator->toArray();
        $payload['meta'] = array_merge($payload['meta'] ?? [], [
            'chars_per_sms' => ShopSmsQuotaService::CHARS_PER_SMS_PART,
            'statuses' => [
                SmsPackageOrder::STATUS_PENDING => 'در انتظار تأیید',
                SmsPackageOrder::STATUS_APPROVED => 'تأیید شده',
                SmsPackageOrder::STATUS_REJECTED => 'رد شده',
            ],
        ]);

        return response($payload, 200);
    }

    /**
     * جزئیات یک درخواست.
     */
    public function show(Request $request, SmsPackageOrder $smsPackageOrder)
    {
        $this->requirePlatformAdmin($request);

        $smsPackageOrder->load(['smsPackage', 'atelier', 'requestedBy', 'reviewedBy']);
        $owners = $this->loadShopOwnersByAtelierIds([(int) $smsPackageOrder->atelier_id]);
        $summary = ShopSmsQuotaService::getSummary((int) $smsPackageOrder->atelier_id);

        return response(array_merge(
            $this->formatOrder($smsPackageOrder, $owners),
            [
                'shop_sms_quota' => $summary['balance'],
                'balance' => $summary['balance'],
            ]
        ), 200);
    }

    /**
     * تأیید درخواست و شارژ اعتبار پیامک فروشگاه.
     */
    public function approve(Request $request, SmsPackageOrder $smsPackageOrder)
    {
        $admin = $this->requirePlatformAdmin($request);

        $fields = $request->validate([
            'admin_note' => 'nullable|string|max:500',
        ]);

        $order = SmsPackageOrderService::approve(
            $smsPackageOrder,
            $admin,
            $fields['admin_note'] ?? null
        );

        $owners = $this->loadShopOwnersByAtelierIds([(int) $order->atelier_id]);
        $newBalance = ShopSmsQuotaService::getBalance((int) $order->atelier_id);

        return response([
            'message' => 'درخواست تأیید شد و اعتبار پیامک فروشگاه شارژ شد.',
            'order' => $this->formatOrder($order, $owners),
            'shop_sms_quota' => $newBalance,
            'balance' => $newBalance,
            'added' => $order->sms_count,
        ], 200);
    }

    /**
     * رد درخواست خرید.
     */
    public function reject(Request $request, SmsPackageOrder $smsPackageOrder)
    {
        $admin = $this->requirePlatformAdmin($request);

        $fields = $request->validate([
            'admin_note' => 'nullable|string|max:500',
        ]);

        $order = SmsPackageOrderService::reject(
            $smsPackageOrder,
            $admin,
            $fields['admin_note'] ?? null
        );

        $owners = $this->loadShopOwnersByAtelierIds([(int) $order->atelier_id]);

        return response([
            'message' => 'درخواست رد شد.',
            'order' => $this->formatOrder($order, $owners),
        ], 200);
    }

    /**
     * @param  array<int, array{phone: string, name: string}>  $ownersByAtelier
     */
    protected function formatOrder(SmsPackageOrder $order, array $ownersByAtelier = []): array
    {
        $order->loadMissing(['smsPackage', 'atelier', 'requestedBy', 'reviewedBy']);
        $aid = (int) $order->atelier_id;
        $owner = $ownersByAtelier[$aid] ?? null;

        return [
            'id' => $order->id,
            'atelier_id' => $aid,
            'shop_name' => $order->atelier ? $order->atelier->name : null,
            'shop_code' => $order->atelier ? $order->atelier->code : null,
            'phone' => $owner['phone'] ?? null,
            'owner_phone' => $owner['phone'] ?? null,
            'owner_name' => $owner['name'] ?? null,
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

    /**
     * @param  int[]  $atelierIds
     * @return array<int, array{phone: string, name: string}>
     */
    protected function loadShopOwnersByAtelierIds(array $atelierIds): array
    {
        if ($atelierIds === []) {
            return [];
        }

        $users = User::query()
            ->whereIn('atelier_id', $atelierIds)
            ->whereHas('roles', function ($q) {
                $q->where('id', User::USER_TYPE_KEY['فروشگاه']);
            })
            ->orderBy('id')
            ->get(['id', 'atelier_id', 'phone', 'name', 'last_name']);

        $map = [];
        foreach ($users as $user) {
            $aid = (int) $user->atelier_id;
            if (! isset($map[$aid])) {
                $map[$aid] = [
                    'phone' => $user->phone,
                    'name' => trim($user->name.' '.$user->last_name),
                ];
            }
        }

        return $map;
    }

    protected function requirePlatformAdmin(Request $request): User
    {
        $actor = $this->shopRequestActor($request);
        if ($actor instanceof Customer) {
            abort(response()->json(['message' => 'این عملیات فقط برای ادمین است.'], 403));
        }
        if (! $actor instanceof User) {
            abort(response()->json(['message' => 'لطفاً وارد شوید.'], 401));
        }
        if (! $actor->roles()->where('id', User::USER_TYPE_KEY['ادمین'])->exists()) {
            abort(response()->json(['message' => 'فقط ادمین می‌تواند درخواست‌ها را مدیریت کند.'], 403));
        }

        return $actor;
    }
}
