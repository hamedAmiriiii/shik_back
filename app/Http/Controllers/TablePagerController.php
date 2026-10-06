<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\ShopTable;
use App\Models\TablePagerCall;
use App\Services\ShopFeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TablePagerController extends Controller
{
    /**
     * فراخوان گارسون توسط مهمان
     * POST /api/{shop}/table-pager
     */
    public function guestStore(Request $request, $shop = null)
    {
        $atelierId = $this->assertPagerFeature($request);

        $request->validate([
            'table_number' => 'required|integer|min:1',
            'note' => 'nullable|string|max:500',
            'kind' => 'nullable|string|in:table,room,میز,اتاق',
        ]);

        $shopTable = ShopTable::resolveFor(
            $atelierId,
            (int) $request->table_number,
            $request->input('kind')
        );

        $existing = TablePagerCall::query()
            ->where('atelier_id', $atelierId)
            ->where('shop_table_id', $shopTable->id)
            ->where('status', TablePagerCall::STATUS_PENDING)
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'درخواست پیجر همین میز در انتظار رسیدگی است.',
                'already_pending' => true,
                'call' => $existing->fresh(['shopTable'])->toPublicArray(),
            ]);
        }

        $row = TablePagerCall::create([
            'atelier_id' => $atelierId,
            'shop_table_id' => $shopTable->id,
            'note' => $request->input('note'),
            'status' => TablePagerCall::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => 'درخواست پیجر ثبت شد',
            'already_pending' => false,
            'call' => $row->fresh(['shopTable'])->toPublicArray(),
        ], 201);
    }

    /**
     * GET /api/{shop}/table-pagers?table_number=1
     */
    public function guestIndex(Request $request, $shop = null)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $request->validate([
            'table_number' => 'required|integer|min:1',
            'status' => ['nullable', Rule::in([
                'open',
                TablePagerCall::STATUS_PENDING,
                TablePagerCall::STATUS_ACKNOWLEDGED,
                TablePagerCall::STATUS_CANCELLED,
            ])],
            'kind' => 'nullable|string|in:table,room,میز,اتاق',
        ]);

        $query = TablePagerCall::query()
            ->where('atelier_id', $atelierId)
            ->with('shopTable')
            ->whereHas('shopTable', function ($q) use ($request) {
                $q->where('table_number', $request->table_number)
                    ->where('kind', ShopTable::normalizeKind($request->input('kind', $request->query('kind'))));
            })
            ->orderByDesc('id');

        $this->applyStatusFilter($query, (string) $request->input('status', 'open'));

        $rows = $query->limit(20)->get()->map(function (TablePagerCall $row) {
            return $row->toPublicArray();
        })->values();

        return response()->json([
            'count' => $rows->count(),
            'calls' => $rows,
        ]);
    }

    public function guestCancel(Request $request, $shop, TablePagerCall $tablePagerCall)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ((int) $tablePagerCall->atelier_id !== $atelierId) {
            abort(response()->json(['message' => 'درخواست یافت نشد'], 404));
        }

        return $this->cancelPending($tablePagerCall, 'customer');
    }

    public function index(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->assertPagerFeature($request);

        $query = TablePagerCall::where('atelier_id', $atelierId)->with('shopTable');
        $this->applyStatusFilter($query, (string) $request->query('status', 'open'));

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
        $payload['data'] = collect($rows->items())->map(function (TablePagerCall $row) {
            return $row->toPublicArray();
        })->values()->all();

        return response()->json($payload);
    }

    public function pendingCount(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->assertPagerFeature($request);

        $base = TablePagerCall::query()
            ->where('atelier_id', $atelierId)
            ->where('status', TablePagerCall::STATUS_PENDING);

        $count = (clone $base)->count();
        $latest = (clone $base)->with('shopTable')->orderByDesc('id')->first();

        return response()->json([
            'count' => $count,
            'latest_id' => $latest ? (int) $latest->id : null,
            'latest_at' => $latest ? $latest->created_at : null,
            'latest_label' => $latest && $latest->shopTable ? $latest->shopTable->display_name : null,
        ]);
    }

    public function ack(Request $request, TablePagerCall $tablePagerCall)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $tablePagerCall);
        $this->assertPagerFeature($request);

        if (! $tablePagerCall->isPending()) {
            return response()->json(['message' => 'فقط درخواست باز قابل رسیدگی است.'], 422);
        }

        $tablePagerCall->update([
            'status' => TablePagerCall::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
        ]);

        return response()->json([
            'message' => 'پیجر رسیدگی شد',
            'call' => $tablePagerCall->fresh(['shopTable'])->toPublicArray(),
        ]);
    }

    public function cancel(Request $request, TablePagerCall $tablePagerCall)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $tablePagerCall);
        $this->assertPagerFeature($request);

        return $this->cancelPending($tablePagerCall, 'staff');
    }

    private function cancelPending(TablePagerCall $row, $cancelledBy)
    {
        if (! $row->isPending()) {
            return response()->json(['message' => 'فقط درخواست باز قابل لغو است.'], 422);
        }

        $row->update(['status' => TablePagerCall::STATUS_CANCELLED]);
        $payload = $row->fresh(['shopTable'])->toPublicArray();
        $payload['cancelled_by'] = $cancelledBy;

        return response()->json([
            'message' => 'درخواست پیجر لغو شد',
            'cancelled_by' => $cancelledBy,
            'call' => $payload,
        ]);
    }

    private function applyStatusFilter($query, $status)
    {
        if ($status === 'open') {
            $query->where('status', TablePagerCall::STATUS_PENDING);

            return;
        }

        if (in_array($status, [
            TablePagerCall::STATUS_PENDING,
            TablePagerCall::STATUS_ACKNOWLEDGED,
            TablePagerCall::STATUS_CANCELLED,
        ], true)) {
            $query->where('status', $status);
        }
    }

    private function assertPagerFeature(Request $request): int
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        Setting::setShopContext($atelierId);
        $cafe = ShopFeatureFlags::enabled($atelierId, ShopFeatureFlags::RESTAURANT_CAFE);
        $room = ShopFeatureFlags::enabled($atelierId, ShopFeatureFlags::ROOM_SERVICES);
        if (! $cafe && ! $room) {
            abort(response()->json(['message' => 'پیجر میز برای این فروشگاه فعال نیست.'], 403));
        }

        return $atelierId;
    }
}
