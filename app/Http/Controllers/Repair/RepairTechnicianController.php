<?php

namespace App\Http\Controllers\Repair;

use App\Http\Controllers\Controller;
use App\Models\RepairPayout;
use App\Models\RepairRequest;
use App\Models\RepairUser;
use App\Services\Repair\RepairRequestService;
use Illuminate\Http\Request;
use RuntimeException;

class RepairTechnicianController extends Controller
{
    public function __construct(protected RepairRequestService $service)
    {
    }

    public function index(Request $request)
    {
        $technician = $this->technician($request);
        $status = $request->query('status');

        $query = RepairRequest::query()
            ->with(['technician', 'customer'])
            ->where('technician_id', $technician->id);
        if ($status === 'open') {
            $query->whereNotIn('status', [RepairRequest::STATUS_COMPLETED, RepairRequest::STATUS_CANCELED]);
        } elseif (is_string($status) && isset(RepairRequest::STATUS_LABELS[$status])) {
            $query->where('status', $status);
        }

        $rows = $query->orderByDesc('id')->limit(200)->get()
            ->map(fn (RepairRequest $r) => $r->toApiArray('technician'))
            ->all();

        return response(['requests' => $rows]);
    }

    public function show(Request $request, RepairRequest $repairRequest)
    {
        $this->assertAssigned($request, $repairRequest);
        $repairRequest->load(['technician', 'customer']);

        return response(['request' => $repairRequest->toApiArray('technician')]);
    }

    public function start(Request $request, RepairRequest $repairRequest)
    {
        $this->assertAssigned($request, $repairRequest);
        try {
            $repair = $this->service->start($repairRequest);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['message' => 'کار شروع شد.', 'request' => $repair->toApiArray('technician')]);
    }

    public function setCost(Request $request, RepairRequest $repairRequest)
    {
        $this->assertAssigned($request, $repairRequest);
        $data = $request->validate([
            'labor_amount' => 'required|integer|min:0|max:100000000000',
            'parts_amount' => 'nullable|integer|min:0|max:100000000000',
            'cost_description' => 'nullable|string|max:2000',
        ]);

        try {
            $repair = $this->service->setCost(
                $repairRequest,
                (int) $data['labor_amount'],
                (int) ($data['parts_amount'] ?? 0),
                $data['cost_description'] ?? null,
                false
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'هزینه ثبت شد و برای پرداخت به مشتری پیامک شد.',
            'request' => $repair->toApiArray('technician'),
        ]);
    }

    public function wallet(Request $request)
    {
        $technician = $this->technician($request);
        $payouts = RepairPayout::query()
            ->where('technician_id', $technician->id)
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (RepairPayout $p) => $p->toApiArray())
            ->all();

        return response([
            'summary' => $this->service->technicianBalance($technician),
            'share_percent' => (float) $technician->labor_share_percent,
            'rating_avg' => $technician->rating_avg !== null ? (float) $technician->rating_avg : null,
            'rating_count' => (int) $technician->rating_count,
            'payouts' => $payouts,
        ]);
    }

    private function technician(Request $request): RepairUser
    {
        /** @var RepairUser $user */
        $user = $request->user();

        return $user;
    }

    private function assertAssigned(Request $request, RepairRequest $repairRequest): void
    {
        if ((int) $repairRequest->technician_id !== (int) $this->technician($request)->id) {
            abort(response()->json(['message' => 'درخواست یافت نشد.'], 404));
        }
    }
}
