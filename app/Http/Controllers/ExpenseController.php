<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;

class ExpenseController extends Controller
{
    /**
     * نمایش لیست هزینه‌ها
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $query = Expense::where('atelier_id', $atelierId)->orderBy('id', 'desc');

        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    // جستجو بر اساس عنوان هزینه
                    if (isset($searchDataModel->title)) {
                        $q->where('title', 'like', '%' . $searchDataModel->title . '%');
                    }
                    // جستجو بر اساس نوع هزینه
                    if (isset($searchDataModel->type)) {
                        $q->orWhere('type', $searchDataModel->type);
                    }
                    // جستجو بر اساس نام کاربر
                    if (isset($searchDataModel->user_name)) {
                        $q->orWhere('user_name', 'like', '%' . $searchDataModel->user_name . '%');
                    }
                } else if (is_string($searchDataModel)) {
                    // اگر یک رشته ساده بود، در عنوان و نام کاربر جستجو می‌کند
                    $q->where('title', 'like', '%' . $searchDataModel . '%')
                      ->orWhere('user_name', 'like', '%' . $searchDataModel . '%');
                }
            });
        }

        // فیلتر type (مستقل از searchFilterModel)
        if ($request->has('type') && in_array($request->input('type'), ['جاری', 'سرمایه'])) {
            $query->where('type', $request->input('type'));
        }

        // فیلتر user_name (مستقل از searchFilterModel)
        if ($request->has('user_name')) {
            $query->where('user_name', 'like', '%' . $request->input('user_name') . '%');
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

        $expenses = $query->paginate();
        return response($expenses, 200);
    }

    /**
     * افزودن هزینه جدید
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت هزینه فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $fields = $request->validate([
            'amount' => 'required|numeric|min:0',
            'title' => 'required|string|max:255',
            'type' => 'nullable|in:جاری,سرمایه',
        ]);

        $user = $this->shopRequestActor($request);
        if (! $user) {
            return response(['error' => 'کاربر احراز هویت نشده است'], 401);
        }

        // ترکیب name و last_name برای user_name
        $fields['user_name'] = trim($user->name . ' ' . $user->last_name);

        // ثبت خودکار تاریخ امروز
        $fields['date'] = Carbon::now()->format('Y-m-d');
        
        // اگر type ارسال نشده باشد، مقدار پیش‌فرض 'جاری' قرار می‌دهیم
        if (!isset($fields['type']) || empty($fields['type'])) {
            $fields['type'] = 'جاری';
        }

        $fields['atelier_id'] = $atelierId;

        $expense = Expense::create($fields);
        return response($expense, 201);
    }

    /**
     * نمایش جزئیات یک هزینه
     */
    public function show(Request $request, Expense $expense)
    {
        $this->assertModelBelongsToStaffAtelier($request, $expense);

        return response($expense, 200);
    }

    /**
     * ویرایش اطلاعات هزینه
     */
    public function update(Request $request, Expense $expense)
    {
        $this->assertModelBelongsToStaffAtelier($request, $expense);

        $fields = $request->validate([
            'user_name' => 'sometimes|required|string',
            'amount' => 'sometimes|required|numeric|min:0',
            'title' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:جاری,سرمایه',
        ]);

        // تاریخ تغییر نمی‌کنه، فقط فیلدهای دیگر آپدیت می‌شن

        $expense->update($fields);
        return response($expense, 200);
    }

    /**
     * حذف هزینه
     */
    public function destroy(Request $request, Expense $expense)
    {
        $this->assertModelBelongsToStaffAtelier($request, $expense);

        $expense->delete();
        return response(['message' => 'هزینه با موفقیت حذف شد'], 200);
    }

    /**
     * آمار کلی هزینه‌ها
     */
    public function statistics(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $expenseQuery = Expense::where('atelier_id', $atelierId);

        $totalExpenses = (clone $expenseQuery)->sum('amount');

        $totalCurrentExpenses = (clone $expenseQuery)->where('type', 'جاری')->sum('amount');

        $totalCapitalExpenses = (clone $expenseQuery)->where('type', 'سرمایه')->sum('amount');

        $expensesByUser = Expense::where('atelier_id', $atelierId)->select(
            'user_name',
            DB::raw('SUM(CASE WHEN type = "جاری" THEN amount ELSE 0 END) as total_current'),
            DB::raw('SUM(CASE WHEN type = "سرمایه" THEN amount ELSE 0 END) as total_capital'),
            DB::raw('SUM(amount) as total')
        )
        ->groupBy('user_name')
        ->orderBy('user_name')
        ->get();

        return response([
            'total_expenses' => (float) $totalExpenses,
            'total_current_expenses' => (float) $totalCurrentExpenses,
            'total_capital_expenses' => (float) $totalCapitalExpenses,
            'expenses_by_user' => $expensesByUser,
            'meta' => ['atelier_id' => $atelierId],
        ], 200);
    }
}

