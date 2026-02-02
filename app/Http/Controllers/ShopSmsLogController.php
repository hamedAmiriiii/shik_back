<?php

namespace App\Http\Controllers;

use App\Models\ShopSmsLog;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ShopSmsLogController extends Controller
{
    /**
     * لیست پیامک‌های ارسال شده فروشگاه
     */
    public function index(Request $request)
    {
        $query = ShopSmsLog::query();

        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    // جستجو بر اساس شماره تلفن
                    if (isset($searchDataModel->phone)) {
                        $q->where('phone', 'like', '%' . $searchDataModel->phone . '%');
                    }
                    // جستجو بر اساس متن پیام
                    if (isset($searchDataModel->message)) {
                        $q->orWhere('message', 'like', '%' . $searchDataModel->message . '%');
                    }
                    // جستجو بر اساس نوع پیامک
                    if (isset($searchDataModel->sms_type)) {
                        $q->orWhere('sms_type', 'like', '%' . $searchDataModel->sms_type . '%');
                    }
                    // جستجو بر اساس ID خرید
                    if (isset($searchDataModel->purchase_id)) {
                        $q->orWhere('purchase_id', 'like', '%' . $searchDataModel->purchase_id . '%');
                    }
                } else if (is_string($searchDataModel)) {
                    // اگر یک رشته ساده بود، در شماره تلفن و متن پیام جستجو می‌کند
                    $q->where('phone', 'like', '%' . $searchDataModel . '%')
                      ->orWhere('message', 'like', '%' . $searchDataModel . '%');
                }
            });
        }

        // فیلتر بر اساس نوع پیامک
        if ($request->has('sms_type')) {
            $query->where('sms_type', $request->input('sms_type'));
        }

        // فیلتر تاریخ (اختیاری)
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

        // فیلتر بازه تاریخ
        if ($request->has('from_date')) {
            $fromDate = Carbon::parse($request->input('from_date'))->startOfDay();
            $query->where('created_at', '>=', $fromDate);
        }
        if ($request->has('to_date')) {
            $toDate = Carbon::parse($request->input('to_date'))->endOfDay();
            $query->where('created_at', '<=', $toDate);
        }

        $perPage = $request->input('per_page', 20);
        $logs = $query->orderBy('id', 'desc')
                     ->paginate($perPage);
        
        $logs->withPath(url()->current());
                       
        return response($logs, 200);
    }

    /**
     * نمایش جزئیات یک پیامک
     */
    public function show(ShopSmsLog $shopSmsLog)
    {
        return response($shopSmsLog, 200);
    }
}

