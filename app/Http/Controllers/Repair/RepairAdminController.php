<?php

namespace App\Http\Controllers\Repair;

use App\Http\Controllers\Controller;
use App\Models\RepairPayout;
use App\Models\RepairRequest;
use App\Models\RepairService;
use App\Models\RepairSetting;
use App\Models\RepairUser;
use App\Services\Repair\RepairNotifier;
use App\Services\Repair\RepairRequestService;
use App\Services\Repair\RepairSms;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class RepairAdminController extends Controller
{
    public function __construct(protected RepairRequestService $service)
    {
    }

    public function dashboard()
    {
        $counts = RepairRequest::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $monthStart = now()->startOfMonth();
        $completedThisMonth = RepairRequest::query()
            ->where('status', RepairRequest::STATUS_COMPLETED)
            ->where('completed_at', '>=', $monthStart);

        $balances = $this->service->balancesReport();

        return response([
            'counts' => collect(RepairRequest::STATUS_LABELS)
                ->map(fn ($label, $status) => ['status' => $status, 'label' => $label, 'count' => (int) ($counts[$status] ?? 0)])
                ->values(),
            'month' => [
                'jobs' => (int) (clone $completedThisMonth)->count(),
                'revenue' => (int) (clone $completedThisMonth)->sum('total_amount'),
                'platform_share' => (int) (clone $completedThisMonth)->sum('platform_share'),
                'technician_share' => (int) (clone $completedThisMonth)->sum('technician_share'),
            ],
            'technicians_balance' => array_sum(array_map(fn ($r) => max(0, $r['balance']), $balances)),
            'pending_technicians' => RepairUser::query()
                ->where('role', RepairUser::ROLE_TECHNICIAN)
                ->where('approval_status', RepairUser::APPROVAL_PENDING)
                ->count(),
            'sms_balance' => RepairSms::enabled() ? app(RepairSms::class)->balance() : null,
        ]);
    }

    public function requests(Request $request)
    {
        $query = RepairRequest::query()->with(['technician', 'customer']);

        $status = $request->query('status');
        if ($status === 'open') {
            $query->whereNotIn('status', [RepairRequest::STATUS_COMPLETED, RepairRequest::STATUS_CANCELED]);
        } elseif (is_string($status) && isset(RepairRequest::STATUS_LABELS[$status])) {
            $query->where('status', $status);
        }
        if ($request->filled('technician_id')) {
            $query->where('technician_id', (int) $request->query('technician_id'));
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', strtr($q, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
            $query->where(function ($w) use ($q, $digits) {
                $w->where('description', 'like', '%'.$q.'%')
                    ->orWhere('address', 'like', '%'.$q.'%')
                    ->orWhere('contact_name', 'like', '%'.$q.'%');
                if ($digits !== '') {
                    $w->orWhere('contact_phone', 'like', '%'.$digits.'%');
                    if (strlen($digits) <= 9) {
                        $w->orWhere('id', (int) $digits);
                    }
                }
            });
        }

        $page = $query->orderByDesc('id')->paginate(min(100, max(10, (int) $request->query('per_page', 30))));

        return response([
            'requests' => collect($page->items())->map(fn (RepairRequest $r) => $r->toApiArray('admin'))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function showRequest(RepairRequest $repairRequest)
    {
        $repairRequest->load(['technician', 'customer']);

        return response(['request' => $repairRequest->toApiArray('admin')]);
    }

    public function assign(Request $request, RepairRequest $repairRequest)
    {
        $data = $request->validate([
            'technician_id' => 'required|integer',
            'admin_note' => 'nullable|string|max:2000',
        ]);
        $technician = RepairUser::query()
            ->where('role', RepairUser::ROLE_TECHNICIAN)
            ->find($data['technician_id']);
        if (! $technician) {
            return response()->json(['message' => 'تعمیرکار یافت نشد.'], 422);
        }

        return $this->run(fn () => $this->service->assign($repairRequest, $technician, $data['admin_note'] ?? null), 'تعمیرکار ارجاع شد.');
    }

    public function setCost(Request $request, RepairRequest $repairRequest)
    {
        $data = $request->validate([
            'labor_amount' => 'required|integer|min:0|max:100000000000',
            'parts_amount' => 'nullable|integer|min:0|max:100000000000',
            'cost_description' => 'nullable|string|max:2000',
            'share_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        return $this->run(fn () => $this->service->setCost(
            $repairRequest,
            (int) $data['labor_amount'],
            (int) ($data['parts_amount'] ?? 0),
            $data['cost_description'] ?? null,
            true,
            isset($data['share_percent']) ? (float) $data['share_percent'] : null
        ), 'هزینه ذخیره شد.');
    }

    public function approveReceipt(RepairRequest $repairRequest)
    {
        if ($repairRequest->status !== RepairRequest::STATUS_PAYMENT_REVIEW) {
            return response()->json(['message' => 'رسیدی برای بررسی وجود ندارد.'], 422);
        }

        return $this->run(fn () => $this->service->confirmPayment($repairRequest, RepairRequest::METHOD_CARD_TO_CARD), 'پرداخت تأیید و کار تکمیل شد.');
    }

    public function rejectReceipt(Request $request, RepairRequest $repairRequest)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->run(fn () => $this->service->rejectReceipt($repairRequest, $data['reason'] ?? null), 'رسید رد شد.');
    }

    public function markPaid(Request $request, RepairRequest $repairRequest)
    {
        $data = $request->validate(['payment_ref' => 'nullable|string|max:100']);

        return $this->run(fn () => $this->service->confirmPayment($repairRequest, RepairRequest::METHOD_CASH, $data['payment_ref'] ?? null), 'پرداخت ثبت و کار تکمیل شد.');
    }

    public function cancel(Request $request, RepairRequest $repairRequest)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->run(fn () => $this->service->cancel($repairRequest, $data['reason'] ?? null), 'درخواست لغو شد.');
    }

    public function updateNote(Request $request, RepairRequest $repairRequest)
    {
        $data = $request->validate(['admin_note' => 'nullable|string|max:2000']);
        $repairRequest->update(['admin_note' => $data['admin_note'] ?? null]);

        return response(['request' => $repairRequest->fresh(['technician', 'customer'])->toApiArray('admin')]);
    }

    public function technicians(Request $request)
    {
        $balances = collect($this->service->balancesReport())->keyBy(fn ($r) => $r['technician']['id']);
        $rows = RepairUser::query()
            ->where('role', RepairUser::ROLE_TECHNICIAN)
            ->with('services')
            ->withCount(['technicianRequests as open_requests' => function ($q) {
                $q->whereNotIn('status', [RepairRequest::STATUS_COMPLETED, RepairRequest::STATUS_CANCELED]);
            }])
            ->orderByRaw("CASE WHEN approval_status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(function (RepairUser $t) use ($balances) {
                $row = $t->toTechnicianArray();
                $row['open_requests'] = (int) $t->open_requests;
                $row['balance'] = (int) ($balances[$t->id]['balance'] ?? 0);

                return $row;
            })
            ->all();

        return response([
            'technicians' => $rows,
            'default_share_percent' => (float) RepairSetting::value('default_labor_share_percent'),
        ]);
    }

    public function storeTechnician(Request $request)
    {
        $data = $this->validateTechnician($request);
        $existing = RepairUser::query()->where('phone', $data['phone'])->first();
        if ($existing && ! $existing->isCustomer()) {
            return response()->json(['message' => 'این شماره قبلاً به‌عنوان '.($existing->isAdmin() ? 'مدیر' : 'تعمیرکار').' ثبت شده است.'], 422);
        }
        $hasRequests = $existing && $existing->customerRequests()->exists();
        if ($hasRequests) {
            return response()->json(['message' => 'این شماره درخواست مشتری ثبت کرده است؛ شمارهٔ دیگری وارد کنید.'], 422);
        }

        $serviceIds = $this->pullServiceIds($data);
        $attributes = array_merge($data, [
            'role' => RepairUser::ROLE_TECHNICIAN,
            'approval_status' => RepairUser::APPROVAL_APPROVED,
            'is_active' => $data['is_active'] ?? true,
        ]);
        if ($existing) {
            $existing->tokens()->delete();
            $existing->update($attributes);
            $technician = $existing;
        } else {
            $technician = RepairUser::create($attributes);
        }
        if ($serviceIds !== null) {
            $technician->services()->sync($serviceIds);
        }

        return response(['message' => 'تعمیرکار ثبت شد.', 'technician' => $technician->load('services')->toTechnicianArray()], 201);
    }

    public function updateTechnician(Request $request, RepairUser $technician)
    {
        if (! $technician->isTechnician()) {
            return response()->json(['message' => 'تعمیرکار یافت نشد.'], 404);
        }
        $data = $this->validateTechnician($request, $technician);
        $serviceIds = $this->pullServiceIds($data);
        $technician->update($data);
        if ($serviceIds !== null) {
            $technician->services()->sync($serviceIds);
        }
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $technician->tokens()->delete();
        }

        return response(['message' => 'ذخیره شد.', 'technician' => $technician->fresh('services')->toTechnicianArray()]);
    }

    public function approveTechnician(Request $request, RepairUser $technician, RepairNotifier $notifier)
    {
        if (! $technician->isTechnician()) {
            return response()->json(['message' => 'تعمیرکار یافت نشد.'], 404);
        }
        $data = $request->validate([
            'labor_share_percent' => 'nullable|numeric|min:0|max:100',
            'service_ids' => 'nullable|array',
            'service_ids.*' => 'integer',
        ]);

        $technician->update(array_filter([
            'approval_status' => RepairUser::APPROVAL_APPROVED,
            'approval_note' => null,
            'is_active' => true,
            'labor_share_percent' => isset($data['labor_share_percent']) ? (float) $data['labor_share_percent'] : null,
        ], fn ($v) => $v !== null) + ['approval_note' => null]);
        $serviceIds = $this->pullServiceIds($data);
        if ($serviceIds !== null) {
            $technician->services()->sync($serviceIds);
        }
        $notifier->technicianApproved($technician);

        return response(['message' => 'تعمیرکار تأیید شد و پیامک ورود برایش رفت.', 'technician' => $technician->fresh('services')->toTechnicianArray()]);
    }

    public function rejectTechnician(Request $request, RepairUser $technician, RepairNotifier $notifier)
    {
        if (! $technician->isTechnician()) {
            return response()->json(['message' => 'تعمیرکار یافت نشد.'], 404);
        }
        if ($technician->technicianRequests()->whereNotIn('status', [RepairRequest::STATUS_COMPLETED, RepairRequest::STATUS_CANCELED])->exists()) {
            return response()->json(['message' => 'این تعمیرکار کار باز دارد؛ ابتدا کارها را به دیگری ارجاع دهید.'], 422);
        }
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);
        $technician->update([
            'approval_status' => RepairUser::APPROVAL_REJECTED,
            'approval_note' => $data['reason'] ?? null,
        ]);
        $technician->tokens()->delete();
        $notifier->technicianRejected($technician);

        return response(['message' => 'ثبت‌نام رد شد.', 'technician' => $technician->fresh('services')->toTechnicianArray()]);
    }

    public function services()
    {
        $counts = DB::table('repair_technician_services')
            ->join('repair_users', 'repair_users.id', '=', 'repair_technician_services.technician_id')
            ->where('repair_users.approval_status', RepairUser::APPROVAL_APPROVED)
            ->where('repair_users.is_active', true)
            ->groupBy('service_id')
            ->pluck(DB::raw('COUNT(*)'), 'service_id');

        return response([
            'services' => RepairService::query()->ordered()->get()->map(function (RepairService $s) use ($counts) {
                $row = $s->toApiArray();
                $row['technicians_count'] = (int) ($counts[$s->id] ?? 0);
                $row['requests_count'] = RepairRequest::query()->where('service_id', $s->id)->count();

                return $row;
            })->all(),
        ]);
    }

    public function storeService(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);
        $service = RepairService::create([
            'name' => trim($data['name']),
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? ((int) RepairService::query()->max('sort_order') + 1),
        ]);

        return response(['message' => 'نوع خدمت اضافه شد.', 'service' => $service->toApiArray()], 201);
    }

    public function updateService(Request $request, RepairService $service)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        $service->update(array_filter($data, fn ($v) => $v !== null));

        return response(['message' => 'ذخیره شد.', 'service' => $service->fresh()->toApiArray()]);
    }

    public function destroyService(RepairService $service)
    {
        if (RepairRequest::query()->where('service_id', $service->id)->exists()) {
            return response()->json(['message' => 'برای این خدمت درخواست ثبت شده است؛ به‌جای حذف، غیرفعالش کنید.'], 422);
        }
        DB::table('repair_technician_services')->where('service_id', $service->id)->delete();
        $service->delete();

        return response(['message' => 'نوع خدمت حذف شد.']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>|null
     */
    private function pullServiceIds(array &$data): ?array
    {
        if (! array_key_exists('service_ids', $data)) {
            return null;
        }
        $ids = is_array($data['service_ids']) ? $data['service_ids'] : [];
        unset($data['service_ids']);

        return RepairService::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function payouts(Request $request)
    {
        $query = RepairPayout::query()->with('technician');
        if ($request->filled('technician_id')) {
            $query->where('technician_id', (int) $request->query('technician_id'));
        }

        return response([
            'payouts' => $query->orderByDesc('paid_on')->orderByDesc('id')->limit(300)->get()
                ->map(fn (RepairPayout $p) => $p->toApiArray())->all(),
        ]);
    }

    public function storePayout(Request $request)
    {
        $data = $request->validate([
            'technician_id' => 'required|integer',
            'amount' => 'required|integer|min:1|max:100000000000',
            'paid_on' => 'nullable|date',
            'method' => 'nullable|string|max:50',
            'note' => 'nullable|string|max:1000',
        ]);
        $technician = RepairUser::query()->where('role', RepairUser::ROLE_TECHNICIAN)->find($data['technician_id']);
        if (! $technician) {
            return response()->json(['message' => 'تعمیرکار یافت نشد.'], 422);
        }

        $payout = RepairPayout::create([
            'technician_id' => $technician->id,
            'amount' => (int) $data['amount'],
            'paid_on' => $data['paid_on'] ?? now()->toDateString(),
            'method' => $data['method'] ?? null,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response([
            'message' => 'تسویه ثبت شد.',
            'payout' => $payout->load('technician')->toApiArray(),
            'summary' => $this->service->technicianBalance($technician),
        ], 201);
    }

    public function destroyPayout(RepairPayout $payout)
    {
        $payout->delete();

        return response(['message' => 'تسویه حذف شد.']);
    }

    public function balances()
    {
        return response(['rows' => $this->service->balancesReport()]);
    }

    public function settings()
    {
        return response(['settings' => RepairSetting::allValues()]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'brand_name' => 'nullable|string|max:100',
            'support_phone' => 'nullable|string|max:30',
            'card_number' => 'nullable|string|max:32',
            'card_holder' => 'nullable|string|max:100',
            'bank_name' => 'nullable|string|max:100',
            'online_payment_enabled' => 'nullable|boolean',
            'card_payment_enabled' => 'nullable|boolean',
            'default_labor_share_percent' => 'nullable|numeric|min:0|max:100',
            'location_mode' => 'nullable|string|in:off,optional,required',
            'map_provider' => 'nullable|string|in:auto,neshan,osm',
        ]);
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            RepairSetting::put($key, $value === null ? '' : (string) $value);
        }

        return response(['message' => 'تنظیمات ذخیره شد.', 'settings' => RepairSetting::allValues()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTechnician(Request $request, ?RepairUser $technician = null): array
    {
        $data = $request->validate([
            'name' => ($technician ? 'sometimes|' : '').'required|string|max:150',
            'phone' => ($technician ? 'sometimes|' : '').'required|string|max:20',
            'specialty' => 'nullable|string|max:255',
            'labor_share_percent' => ($technician ? 'sometimes|' : '').'required|numeric|min:0|max:100',
            'card_number' => 'nullable|string|max:32',
            'address' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'sometimes|boolean',
            'service_ids' => 'sometimes|array',
            'service_ids.*' => 'integer',
        ]);

        if (array_key_exists('phone', $data)) {
            $phone = PhoneTools::normalizeIranPhone($data['phone']);
            if (! PhoneTools::isValidIranMobile($phone)) {
                abort(response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422));
            }
            if ($technician) {
                $request->merge(['phone' => $phone]);
                $request->validate(['phone' => [Rule::unique('repair_users', 'phone')->ignore($technician->id)]], [
                    'phone.unique' => 'این شماره برای کاربر دیگری ثبت شده است.',
                ]);
            }
            $data['phone'] = $phone;
        }

        return $data;
    }

    private function run(callable $action, string $message)
    {
        try {
            /** @var RepairRequest $repair */
            $repair = $action();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['message' => $message, 'request' => $repair->toApiArray('admin')]);
    }
}
