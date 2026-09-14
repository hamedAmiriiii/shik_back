<?php

namespace App\Http\Controllers;

use App\Models\ShopSmsLog;
use App\Tools\SmsTools;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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
                    if (isset($searchDataModel->delivery_status) && Schema::hasColumn('shop_sms_logs', 'delivery_status')) {
                        $q->orWhere('delivery_status', $searchDataModel->delivery_status);
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

        if ($request->filled('delivery_status') && Schema::hasColumn('shop_sms_logs', 'delivery_status')) {
            $query->where('delivery_status', $request->input('delivery_status'));
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

    /**
     * استعلام وضعیت تحویل از سامانه شینا
     */
    public function refreshStatus(Request $request, ShopSmsLog $shopSmsLog)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopSmsLog);

        if (! Schema::hasColumn('shop_sms_logs', 'delivery_status')) {
            return response([
                'message' => 'ستون وضعیت پیامک هنوز روی دیتابیس اعمال نشده است.',
            ], 503);
        }

        if (! $shopSmsLog->canRefreshStatus()) {
            return response([
                'message' => $shopSmsLog->isFinalStatus()
                    ? 'وضعیت این پیامک نهایی است و نیازی به به‌روزرسانی ندارد.'
                    : 'شناسه پیامک برای استعلام وضعیت موجود نیست.',
                'data' => $shopSmsLog,
            ], 422);
        }

        try {
            $updated = SmsTools::refreshShopSmsLogStatus($shopSmsLog);
        } catch (\Throwable $e) {
            return response([
                'message' => 'خطا در دریافت وضعیت از سامانه پیامک',
                'error' => $e->getMessage(),
            ], 502);
        }

        return response([
            'message' => 'وضعیت پیامک به‌روزرسانی شد',
            'data' => $updated,
        ], 200);
    }

    /**
     * استعلام دسته‌ای وضعیت‌های باز همین فروشگاه
     */
    public function refreshPending(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        if (! Schema::hasColumn('shop_sms_logs', 'delivery_status')) {
            return response([
                'message' => 'ستون وضعیت پیامک هنوز روی دیتابیس اعمال نشده است.',
                'updated' => 0,
            ], 503);
        }

        $limit = min(50, max(1, (int) $request->input('limit', 30)));

        $logs = ShopSmsLog::where('atelier_id', $atelierId)
            ->where(function ($q) {
                $q->whereNull('delivery_status')
                    ->orWhereNotIn('delivery_status', ShopSmsLog::FINAL_STATUSES);
            })
            ->where(function ($q) {
                $q->whereNotNull('reference_id')
                    ->orWhereNotNull('batch_id');
            })
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        $updated = 0;
        $failed = 0;

        foreach ($logs as $log) {
            try {
                SmsTools::refreshShopSmsLogStatus($log);
                $updated++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        return response([
            'message' => 'وضعیت پیامک‌ها بررسی شد',
            'checked' => $logs->count(),
            'updated' => $updated,
            'failed' => $failed,
        ], 200);
    }
}
