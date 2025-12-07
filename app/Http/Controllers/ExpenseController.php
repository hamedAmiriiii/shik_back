<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;

class ExpenseController extends Controller
{
    /**
     * نمایش لیست هزینه‌ها
     */
    public function index(Request $request)
    {
        $query = Expense::with('user')->orderBy('id', 'desc');

        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    // جستجو بر اساس عنوان هزینه
                    if (isset($searchDataModel->title)) {
                        $q->where('title', 'like', '%' . $searchDataModel->title . '%');
                    }
                    // جستجو بر اساس نام کاربر
                    if (isset($searchDataModel->user_name)) {
                        $q->orWhereHas('user', function($userQuery) use ($searchDataModel) {
                            $userQuery->where('name', 'like', '%' . $searchDataModel->user_name . '%');
                        });
                    }
                } else if (is_string($searchDataModel)) {
                    // اگر یک رشته ساده بود، در عنوان و نام کاربر جستجو می‌کند
                    $q->where('title', 'like', '%' . $searchDataModel . '%')
                      ->orWhereHas('user', function($userQuery) use ($searchDataModel) {
                          $userQuery->where('name', 'like', '%' . $searchDataModel . '%');
                      });
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
                $query->whereMonth('date', Carbon::now()->month)
                      ->whereYear('date', Carbon::now()->year);
            } elseif ($request->filter === 'year') {
                $query->whereYear('date', Carbon::now()->year);
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
        $fields = $request->validate([
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0',
            'title' => 'required|string|max:255',
        ]);

        // ثبت خودکار تاریخ امروز
        $fields['date'] = Carbon::now()->format('Y-m-d');

        $expense = Expense::create($fields);
        return response($expense->load('user'), 201);
    }

    /**
     * نمایش جزئیات یک هزینه
     */
    public function show(Expense $expense)
    {
        return response($expense->load('user'), 200);
    }

    /**
     * ویرایش اطلاعات هزینه
     */
    public function update(Request $request, Expense $expense)
    {
        $fields = $request->validate([
            'user_id' => 'sometimes|required|exists:users,id',
            'amount' => 'sometimes|required|numeric|min:0',
            'title' => 'sometimes|required|string|max:255',
        ]);

        // تاریخ تغییر نمی‌کنه، فقط فیلدهای دیگر آپدیت می‌شن

        $expense->update($fields);
        return response($expense->load('user'), 200);
    }

    /**
     * حذف هزینه
     */
    public function destroy(Expense $expense)
    {
        $expense->delete();
        return response(['message' => 'هزینه با موفقیت حذف شد'], 200);
    }
}

