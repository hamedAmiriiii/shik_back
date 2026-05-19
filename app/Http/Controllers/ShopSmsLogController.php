<?php

namespace App\Http\Controllers;

use App\Models\ShopSmsLog;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ShopSmsLogController extends Controller
{
    /**
     * لیست پیامک‌های ارسال شده فروشگاه (همان atelier_id)
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $query = ShopSmsLog::where('atelier_id', $atelierId);

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    if (isset($searchDataModel->phone)) {
                        $q->where('phone', 'like', '%'.$searchDataModel->phone.'%');
                    }
                    if (isset($searchDataModel->message)) {
                        $q->orWhere('message', 'like', '%'.$searchDataModel->message.'%');
                    }
                    if (isset($searchDataModel->sms_type)) {
                        $q->orWhere('sms_type', 'like', '%'.$searchDataModel->sms_type.'%');
                    }
                    if (isset($searchDataModel->purchase_id)) {
                        $q->orWhere('purchase_id', 'like', '%'.$searchDataModel->purchase_id.'%');
                    }
                } elseif (is_string($searchDataModel)) {
                    $q->where('phone', 'like', '%'.$searchDataModel.'%')
                        ->orWhere('message', 'like', '%'.$searchDataModel.'%');
                }
            });
        }

        if ($request->has('sms_type')) {
            $query->where('sms_type', $request->input('sms_type'));
        }

        if ($request->has('filter')) {
            $filter = $request->input('filter');
            if ($filter === 'today') {
                $query->whereDate('created_at', Carbon::today());
            } elseif ($filter === 'week') {
                $query->where('created_at', '>=', Carbon::now()->subWeek());
            } elseif ($filter === 'month') {
                $query->where('created_at', '>=', Carbon::now()->subMonth());
            } elseif ($filter === 'year') {
                $query->where('created_at', '>=', Carbon::now()->subYear());
            }
        }

        if ($request->has('from_date')) {
            $fromDate = Carbon::parse($request->input('from_date'))->startOfDay();
            $query->where('created_at', '>=', $fromDate);
        }
        if ($request->has('to_date')) {
            $toDate = Carbon::parse($request->input('to_date'))->endOfDay();
            $query->where('created_at', '<=', $toDate);
        }

        $perPage = $request->input('per_page', 20);
        $logs = $query->orderBy('id', 'desc')->paginate($perPage);

        $logs->withPath(url()->current());

        return response($logs, 200);
    }

    /**
     * نمایش جزئیات یک پیامک
     */
    public function show(Request $request, ShopSmsLog $shopSmsLog)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopSmsLog);

        return response($shopSmsLog, 200);
    }
}
