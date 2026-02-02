<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;

class InvoiceController extends Controller
{
    /**
     * نمایش لیست فاکتورها
     */
    public function index(Request $request)
    {
        $query = Invoice::orderBy('id', 'desc');

        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    // جستجو بر اساس عنوان فاکتور
                    if (isset($searchDataModel->title)) {
                        $q->where('title', 'like', '%' . $searchDataModel->title . '%');
                    }
                    // جستجو بر اساس توضیح
                    if (isset($searchDataModel->description)) {
                        $q->orWhere('description', 'like', '%' . $searchDataModel->description . '%');
                    }
                    // جستجو بر اساس نام کاربر
                    if (isset($searchDataModel->user_name)) {
                        $q->orWhere('user_name', 'like', '%' . $searchDataModel->user_name . '%');
                    }
                } else if (is_string($searchDataModel)) {
                    // اگر یک رشته ساده بود، در عنوان، توضیح و نام کاربر جستجو می‌کند
                    $q->where('title', 'like', '%' . $searchDataModel . '%')
                      ->orWhere('description', 'like', '%' . $searchDataModel . '%')
                      ->orWhere('user_name', 'like', '%' . $searchDataModel . '%');
                }
            });
        }

        // فیلتر تاریخ
        if ($request->has('filter')) {
            if ($request->filter === 'today') {
                $query->whereDate('date', Carbon::today());
            } elseif ($request->filter === 'week') {
                // فیلتر هفته شمسی (شنبه تا جمعه)
                $now = Jalalian::now();
                $dayOfWeek = $now->getDayOfWeek(); // 0 = شنبه, 6 = جمعه
                $startOfWeekJalali = Jalalian::now()->subDays($dayOfWeek);
                $endOfWeekJalali = Jalalian::now()->addDays(6 - $dayOfWeek);
                $startOfWeek = $startOfWeekJalali->toCarbon()->startOfDay();
                $endOfWeek = $endOfWeekJalali->toCarbon()->endOfDay();
                $query->whereBetween('date', [$startOfWeek, $endOfWeek]);
            } elseif ($request->filter === 'month') {
                // فیلتر ماه شمسی
                $now = Jalalian::now();
                $year = $now->getYear();
                $month = $now->getMonth();
                $startOfMonthJalali = new Jalalian($year, $month, 1);
                $startOfMonth = $startOfMonthJalali->toCarbon()->startOfDay();
                // محاسبه آخرین روز ماه شمسی: اضافه کردن یک ماه و کسر یک روز
                $endOfMonthJalali = (new Jalalian($year, $month, 1))->addMonths(1)->subDays(1);
                $endOfMonth = $endOfMonthJalali->toCarbon()->endOfDay();
                $query->whereBetween('date', [$startOfMonth, $endOfMonth]);
            } elseif ($request->filter === 'year') {
                // فیلتر سال شمسی
                $now = Jalalian::now();
                $year = $now->getYear();
                // آخرین روز سال شمسی (29 اسفند)
                $startOfYear = (new Jalalian($year, 1, 1))->toCarbon()->startOfDay();
                $endOfYear = (new Jalalian($year, 12, 29))->toCarbon()->endOfDay();
                $query->whereBetween('date', [$startOfYear, $endOfYear]);
            } elseif ($request->filter === 'range') {
                // فیلتر بازه تاریخ شمسی
                if ($request->has('from_date')) {
                    $fromDate = json_decode($request->input('from_date'));
                    $fromCarbon = (new Jalalian($fromDate->year, $fromDate->month, $fromDate->day))->toCarbon()->startOfDay();
                    $query->where('date', '>=', $fromCarbon);
                }
                if ($request->has('to_date')) {
                    $toDate = json_decode($request->input('to_date'));
                    $toCarbon = (new Jalalian($toDate->year, $toDate->month, $toDate->day))->toCarbon()->endOfDay();
                    $query->where('date', '<=', $toCarbon);
                }
            }
        }

        // محاسبه جمع کل مبالغ برای فاکتورهای فیلتر شده (قبل از paginate)
        $totalAmount = (clone $query)->sum('amount');
        
        // دریافت تعداد آیتم در هر صفحه از request (پیش‌فرض 20)
        $perPage = $request->input('per_page', 20);
        
        $invoices = $query->paginate($perPage);
        
        // حفظ مسیر URL برای pagination
        $invoices->withPath(url()->current());
        
        // اضافه کردن جمع کل به meta
        $invoicesArray = $invoices->toArray();
        $invoicesArray['total_amount'] = (float) $totalAmount;
        
        return response($invoicesArray, 200);
    }

    /**
     * افزودن فاکتور جدید
     */
    public function store(Request $request)
    {
        $fields = $request->validate([
            'amount' => 'required|numeric|min:0',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        // دریافت نام کاربر از لاگین
        $user = $request->user();
        if ($user) {
            // ترکیب name و last_name برای user_name
            $fields['user_name'] = trim($user->name . ' ' . $user->last_name);
        }

        // ثبت خودکار تاریخ امروز
        $fields['date'] = Carbon::now()->format('Y-m-d');

        // ذخیره فاکتور
        $invoice = Invoice::create($fields);
        return response($invoice, 201);
    }

    /**
     * نمایش جزئیات یک فاکتور
     */
    public function show(Invoice $invoice)
    {
        return response($invoice, 200);
    }

    /**
     * ویرایش اطلاعات فاکتور
     */
    public function update(Request $request, Invoice $invoice)
    {
        $fields = $request->validate([
            'amount' => 'sometimes|required|numeric|min:0',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'date' => 'sometimes|required|date',
        ]);

        $invoice->update($fields);
        return response($invoice, 200);
    }

    /**
     * حذف فاکتور
     */
    public function destroy(Invoice $invoice)
    {
        $invoice->delete();
        return response(['message' => 'فاکتور با موفقیت حذف شد'], 200);
    }
}

