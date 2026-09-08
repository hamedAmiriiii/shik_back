<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\ShopService;
use App\Models\ShopTable;
use App\Models\TableServiceRequest;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TableServiceRequestController extends Controller
{
    /**
     * ثبت درخواست خدمت توسط مهمان
     * POST /api/{shop}/table-service-request
     */
    public function guestStore(Request $request, $shop = null)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        Setting::setShopContext($atelierId);

        if (! Setting::isEnabled('room_services_enabled', false)) {
            return response()->json(['message' => 'خدمات این فروشگاه فعال نیست.'], 403);
        }

        if ($request->filled('phone')) {
            $request->merge([
                'phone' => PhoneTools::normalizeIranPhone($request->input('phone')),
            ]);
        }

        $request->validate([
            'table_number' => 'required|integer|min:1',
            'shop_service_id' => 'required|integer',
            'note' => 'nullable|string|max:500',
            'phone' => ['nullable', 'string', 'regex:/^09\d{9}$/'],
            'kind' => 'nullable|string|in:table,room,میز,اتاق',
        ]);

        $shopTable = ShopTable::resolveFor(
            $atelierId,
            (int) $request->table_number,
            $request->input('kind')
        );

        $service = ShopService::where('atelier_id', $atelierId)
            ->where('id', $request->shop_service_id)
            ->where('is_active', true)
            ->first();

        if (! $service) {
            return response()->json(['message' => 'این خدمت در فروشگاه موجود نیست.'], 422);
        }

        $duplicate = TableServiceRequest::query()
            ->where('atelier_id', $atelierId)
            ->where('shop_table_id', $shopTable->id)
            ->where('shop_service_id', $service->id)
            ->whereIn('status', [TableServiceRequest::STATUS_PENDING, TableServiceRequest::STATUS_SCHEDULED])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'همین خدمت برای این اتاق/میز در انتظار انجام است.',
                'code' => 'duplicate_pending_service',
            ], 409);
        }

        $row = TableServiceRequest::create([
            'atelier_id' => $atelierId,
            'shop_table_id' => $shopTable->id,
            'shop_service_id' => $service->id,
            'service_name' => $service->name,
            'icon_key' => $service->icon_key ?: 'other',
            'phone' => $request->input('phone'),
            'note' => $request->note,
            'status' => TableServiceRequest::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => 'درخواست خدمت ثبت شد',
            'request' => $row->fresh(['shopTable'])->toPublicArray(),
        ], 201);
    }

    /**
     * درخواست‌های جاری مهمان
     * GET /api/{shop}/table-service-requests?table_number=1
     */
    public function guestIndex(Request $request, $shop = null)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        Setting::setShopContext($atelierId);

        if ($request->filled('phone')) {
            $request->merge([
                'phone' => PhoneTools::normalizeIranPhone($request->input('phone')),
            ]);
        }

        $request->validate([
            'table_number' => 'nullable|integer|min:1',
            'phone' => 'nullable|string|regex:/^09\d{9}$/',
            'status' => ['nullable', Rule::in([
                'open',
                TableServiceRequest::STATUS_PENDING,
                TableServiceRequest::STATUS_SCHEDULED,
                TableServiceRequest::STATUS_DONE,
                TableServiceRequest::STATUS_CANCELLED,
            ])],
        ]);

        if (! $request->filled('table_number') && ! $request->filled('phone')) {
            return response()->json(['message' => 'شماره میز یا شماره موبایل را بفرستید.'], 422);
        }

        $query = TableServiceRequest::query()
            ->where('atelier_id', $atelierId)
            ->with('shopTable')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $this->applyStatusFilter($query, (string) $request->status);
        } else {
            $this->applyStatusFilter($query, 'open');
        }

        if ($request->filled('table_number')) {
            $query->whereHas('shopTable', function ($q) use ($request) {
                $q->where('table_number', $request->table_number)
                    ->where('kind', ShopTable::normalizeKind($request->input('kind', $request->query('kind'))));
            });
        }

        if ($request->filled('phone')) {
            $query->where('phone', $request->input('phone'));
        }

        $rows = $query->limit(50)->get()->map(fn (TableServiceRequest $row) => $row->toPublicArray())->values();

        return response()->json([
            'count' => $rows->count(),
            'requests' => $rows,
        ]);
    }

    public function guestCancel(Request $request, $shop, TableServiceRequest $tableServiceRequest)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $this->assertGuestRequest($tableServiceRequest, $atelierId);

        if ($request->filled('phone')) {
            $request->merge([
                'phone' => PhoneTools::normalizeIranPhone($request->input('phone')),
            ]);
        }

        if ($tableServiceRequest->phone) {
            $request->validate([
                'phone' => 'required|string|regex:/^09\d{9}$/',
            ]);
            if ($request->input('phone') !== $tableServiceRequest->phone) {
                return response()->json(['message' => 'شماره موبایل با این درخواست مطابقت ندارد.'], 422);
            }
        }

        return $this->cancelPending($tableServiceRequest, 'customer');
    }

    public function index(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $this->assertRoomServicesEnabled($atelierId);

        $query = TableServiceRequest::where('atelier_id', $atelierId)
            ->with('shopTable');

        $status = $request->query('status', 'open');
        $this->applyStatusFilter($query, (string) $status);

        if ($request->filled('table_number')) {
            $query->whereHas('shopTable', function ($q) use ($request) {
                $q->where('table_number', $request->table_number);
                if ($request->filled('kind')) {
                    $q->where('kind', ShopTable::normalizeKind($request->input('kind')));
                }
            });
        }

        $rows = $query->orderByDesc('id')->paginate(40);
        $payload = $rows->toArray();
        $payload['data'] = collect($rows->items())->map(
            fn (TableServiceRequest $row) => $row->toPublicArray()
        )->values()->all();

        return response()->json($payload);
    }

    public function pendingCount(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $this->assertRoomServicesEnabled($atelierId);

        $base = TableServiceRequest::query()
            ->where('atelier_id', $atelierId)
            ->whereIn('status', [TableServiceRequest::STATUS_PENDING, TableServiceRequest::STATUS_SCHEDULED]);

        $count = (clone $base)->count();
        $latest = (clone $base)->with('shopTable')->orderByDesc('id')->first();

        return response()->json([
            'count' => $count,
            'latest_id' => $latest ? (int) $latest->id : null,
            'latest_at' => $latest ? $latest->created_at : null,
            'latest_label' => $latest && $latest->shopTable ? $latest->shopTable->display_name : null,
        ]);
    }

    public function schedule(Request $request, TableServiceRequest $tableServiceRequest)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $tableServiceRequest);
        $this->assertRoomServicesEnabled((int) $tableServiceRequest->atelier_id);

        if (! $tableServiceRequest->isOpen()) {
            return response()->json(['message' => 'فقط درخواست باز قابل زمان‌بندی است.'], 422);
        }

        $request->validate([
            'scheduled_at' => 'required|date',
        ]);

        $tableServiceRequest->update([
            'scheduled_at' => $request->input('scheduled_at'),
            'status' => TableServiceRequest::STATUS_SCHEDULED,
        ]);

        return response()->json([
            'message' => 'زمان انجام ثبت شد',
            'request' => $tableServiceRequest->fresh(['shopTable'])->toPublicArray(),
        ]);
    }

    public function done(Request $request, TableServiceRequest $tableServiceRequest)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $tableServiceRequest);
        $this->assertRoomServicesEnabled((int) $tableServiceRequest->atelier_id);

        if (! $tableServiceRequest->isOpen()) {
            return response()->json(['message' => 'فقط درخواست باز قابل انجام است.'], 422);
        }

        if (! $tableServiceRequest->scheduled_at) {
            return response()->json(['message' => 'اول تاریخ و ساعت انجام را ثبت کنید، بعد وضعیت انجام شد بزنید.'], 422);
        }

        $tableServiceRequest->update([
            'status' => TableServiceRequest::STATUS_DONE,
            'done_at' => now(),
        ]);

        return response()->json([
            'message' => 'خدمت انجام شد',
            'request' => $tableServiceRequest->fresh(['shopTable'])->toPublicArray(),
        ]);
    }

    public function cancel(Request $request, TableServiceRequest $tableServiceRequest)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $tableServiceRequest);
        $this->assertRoomServicesEnabled((int) $tableServiceRequest->atelier_id);

        return $this->cancelPending($tableServiceRequest, 'staff');
    }

    private function cancelPending(TableServiceRequest $row, string $cancelledBy)
    {
        if (! $row->isOpen()) {
            return response()->json(['message' => 'فقط درخواست باز قابل لغو است.'], 422);
        }

        $row->update(['status' => TableServiceRequest::STATUS_CANCELLED]);
        $payload = $row->fresh(['shopTable'])->toPublicArray();
        $payload['cancelled_by'] = $cancelledBy;

        return response()->json([
            'message' => 'درخواست لغو شد',
            'cancelled_by' => $cancelledBy,
            'request' => $payload,
        ]);
    }

    private function applyStatusFilter($query, string $status): void
    {
        if ($status === 'open') {
            $query->whereIn('status', [
                TableServiceRequest::STATUS_PENDING,
                TableServiceRequest::STATUS_SCHEDULED,
            ]);

            return;
        }

        if (in_array($status, [
            TableServiceRequest::STATUS_PENDING,
            TableServiceRequest::STATUS_SCHEDULED,
            TableServiceRequest::STATUS_DONE,
            TableServiceRequest::STATUS_CANCELLED,
        ], true)) {
            $query->where('status', $status);
        }
    }

    private function assertRoomServicesEnabled(int $atelierId): void
    {
        Setting::setShopContext($atelierId);
        if (! Setting::isEnabled('room_services_enabled', false)) {
            abort(response()->json(['message' => 'خدمات اتاق برای این فروشگاه فعال نیست.'], 403));
        }
    }

    private function assertGuestRequest(TableServiceRequest $row, int $atelierId): void
    {
        if ((int) $row->atelier_id !== $atelierId) {
            abort(response()->json(['message' => 'درخواست یافت نشد'], 404));
        }
    }
}
