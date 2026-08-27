<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgencyRequest;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * گرید مدیریت درخواست‌های اعطای نمایندگی وبینو.
 */
class AgencyRequestController extends Controller
{
    /**
     * لیست درخواست‌ها با جست‌وجو و صفحه‌بندی.
     */
    public function index(Request $request)
    {
        $this->requirePlatformAdmin($request);

        $query = AgencyRequest::query()
            ->with(['state:id,name', 'city:id,name'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('state_id')) {
            $query->where('state_id', (int) $request->input('state_id'));
        }
        if ($request->filled('city_id')) {
            $query->where('city_id', (int) $request->input('city_id'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        $term = $request->input('search', $request->input('q'));
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if (is_object($searchDataModel)) {
            $term = $searchDataModel->search ?? $searchDataModel->name ?? $term;
            if (isset($searchDataModel->status)) {
                $query->where('status', $searchDataModel->status);
            }
            if (isset($searchDataModel->state_id)) {
                $query->where('state_id', (int) $searchDataModel->state_id);
            }
        } elseif (is_string($searchDataModel) && $searchDataModel !== '') {
            $term = $searchDataModel;
        }

        if (is_string($term) && trim($term) !== '') {
            $term = trim($term);
            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'like', '%'.$term.'%')
                    ->orWhere('last_name', 'like', '%'.$term.'%')
                    ->orWhere('phone', 'like', '%'.$term.'%')
                    ->orWhere('state_name', 'like', '%'.$term.'%')
                    ->orWhere('city_name', 'like', '%'.$term.'%');
            });
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        $paginator = $query->paginate($perPage);
        $paginator->withPath(url()->current());

        $payload = $paginator->toArray();
        $payload['meta'] = array_merge($payload['meta'] ?? [], [
            'statuses' => AgencyRequest::STATUSES,
            'educations' => AgencyRequest::EDUCATIONS,
            'counts' => AgencyRequest::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
        ]);

        return response($payload, 200);
    }

    public function show(Request $request, AgencyRequest $agencyRequest)
    {
        $this->requirePlatformAdmin($request);

        $agencyRequest->load(['state:id,name', 'city:id,name']);

        return response($agencyRequest, 200);
    }

    /**
     * تغییر وضعیت پیگیری و یادداشت ادمین.
     */
    public function update(Request $request, AgencyRequest $agencyRequest)
    {
        $this->requirePlatformAdmin($request);

        $fields = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(array_keys(AgencyRequest::STATUSES))],
            'admin_note' => 'sometimes|nullable|string|max:1000',
        ]);

        if (empty($fields)) {
            return response()->json(['message' => 'موردی برای بروزرسانی ارسال نشده است.'], 422);
        }

        $agencyRequest->fill($fields)->save();

        return response([
            'message' => 'درخواست بروزرسانی شد.',
            'data' => $agencyRequest->fresh(['state:id,name', 'city:id,name']),
        ], 200);
    }

    public function destroy(Request $request, AgencyRequest $agencyRequest)
    {
        $this->requirePlatformAdmin($request);

        $agencyRequest->delete();

        return response(['message' => 'درخواست حذف شد.'], 200);
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
            abort(response()->json(['message' => 'فقط ادمین می‌تواند درخواست‌های نمایندگی را مدیریت کند.'], 403));
        }

        return $actor;
    }
}
