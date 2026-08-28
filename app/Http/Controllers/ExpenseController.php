<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ManualTrade;
use App\Services\DocumentPaymentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;
use RuntimeException;

class ExpenseController extends Controller
{
    /**
     * نمایش لیست هزینه‌ها
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $query = Expense::where('atelier_id', $atelierId)->orderBy('id', 'desc');

        if ($this->supportsPaymentAccount('expenses')) {
            $query->with(['shopAccount', 'cheque']);

            // فیلتر بر اساس حساب برداشت (فروشگاه یا تنخواه)
            if ($request->filled('shop_account_id')) {
                $query->where('shop_account_id', (int) $request->input('shop_account_id'));
            }
        }

        if (DocumentPaymentService::supports(new Expense()) && $request->filled('payment_method') && in_array($request->input('payment_method'), DocumentPaymentService::methods(), true)) {
            $query->where('payment_method', $request->input('payment_method'));
        }
        if (DocumentPaymentService::supports(new Expense()) && $request->filled('payment_status') && in_array($request->input('payment_status'), ['paid', 'unpaid'], true)) {
            $query->where('payment_status', $request->input('payment_status'));
        }

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

        $fields = $request->validate(array_merge([
            'amount' => 'required|numeric|min:0',
            'title' => 'required|string|max:255',
            'type' => 'nullable|in:جاری,سرمایه',
        ], $this->paymentAccountRules('expenses'), DocumentPaymentService::requestRules()));

        $accountError = $this->paymentAccountError($atelierId, $fields['shop_account_id'] ?? null);
        if ($accountError) {
            return response()->json(['message' => $accountError], 422);
        }

        $user = $this->shopRequestActor($request);
        if (! $user) {
            return response(['error' => 'کاربر احراز هویت نشده است'], 401);
        }

        $fields['user_name'] = trim($user->name . ' ' . $user->last_name);
        $fields['date'] = Carbon::now()->format('Y-m-d');
        if (!isset($fields['type']) || empty($fields['type'])) {
            $fields['type'] = 'جاری';
        }
        $fields['atelier_id'] = $atelierId;

        try {
            $expense = DB::transaction(function () use ($fields, $atelierId, $request) {
                $payment = DocumentPaymentService::resolveOnCreate(
                    $atelierId,
                    $fields,
                    (float) $fields['amount'],
                    'expenses'
                );
                $chequePayload = [
                    'cheque' => $fields['cheque'] ?? null,
                    'cheque_id' => $fields['cheque_id'] ?? null,
                    'payment_method' => $payment['payment_method'] ?? ($fields['payment_method'] ?? null),
                ];
                unset($fields['cheque'], $fields['cheque_id'], $fields['payment_method']);
                $fields = array_merge($fields, $payment);

                $expense = Expense::create($fields);
                DocumentPaymentService::attachChequeFromRequest($expense, $chequePayload, $fields['user_name']);

                return $expense;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->withAccount($expense->fresh(['shopAccount', 'cheque'])), 201);
    }

    /**
     * نمایش جزئیات یک هزینه
     */
    public function show(Request $request, Expense $expense)
    {
        $this->assertModelBelongsToStaffAtelier($request, $expense);

        return response($this->withAccount($expense), 200);
    }

    /**
     * ویرایش اطلاعات هزینه
     */
    public function update(Request $request, Expense $expense)
    {
        $this->assertModelBelongsToStaffAtelier($request, $expense);

        $fields = $request->validate(array_merge([
            'user_name' => 'sometimes|required|string',
            'amount' => 'sometimes|required|numeric|min:0',
            'title' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:جاری,سرمایه',
        ], $this->paymentAccountRules('expenses')));

        if (array_key_exists('shop_account_id', $fields)) {
            $accountError = $this->paymentAccountError((int) $expense->atelier_id, $fields['shop_account_id']);
            if ($accountError) {
                return response()->json(['message' => $accountError], 422);
            }
        }

        try {
            $newAmount = (float) ($fields['amount'] ?? $expense->amount);
            $accountId = $fields['shop_account_id'] ?? $expense->shop_account_id;
            if (DocumentPaymentService::isPaid($expense) && $accountId) {
                DocumentPaymentService::assertCanDebit(
                    (int) $expense->atelier_id,
                    (int) $accountId,
                    $newAmount,
                    $expense
                );
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $expense->update($fields);

        return response($this->withAccount($expense->fresh()), 200);
    }

    /**
     * حذف هزینه
     */
    public function destroy(Request $request, Expense $expense)
    {
        $this->assertModelBelongsToStaffAtelier($request, $expense);

        if ($expense->cheque && $expense->cheque->status === \App\Models\Cheque::STATUS_CLEARED) {
            return response()->json([
                'message' => 'این هزینه با چک وصول‌شده ثبت شده و قابل حذف نیست.',
            ], 422);
        }

        if ($expense->cheque && $expense->cheque->status === \App\Models\Cheque::STATUS_PENDING) {
            $expense->cheque->update(['status' => \App\Models\Cheque::STATUS_CANCELLED, 'expense_id' => null]);
        }

        $expense->delete();
        return response(['message' => 'هزینه با موفقیت حذف شد'], 200);
    }

    /**
     * تسویه نسیه/چک از حساب — فقط اگر موجودی کافی باشد.
     * POST /api/expenses/{expense}/settle  { "shop_account_id": 1 }
     */
    public function settle(Request $request, Expense $expense)
    {
        $this->assertModelBelongsToStaffAtelier($request, $expense);

        $fields = $request->validate([
            'shop_account_id' => 'required|integer|exists:shop_accounts,id',
        ]);

        $accountError = $this->paymentAccountError((int) $expense->atelier_id, $fields['shop_account_id']);
        if ($accountError) {
            return response()->json(['message' => $accountError], 422);
        }

        try {
            $expense = DocumentPaymentService::settle($expense, (int) $fields['shop_account_id']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->withAccount($expense), 200);
    }

    protected function withAccount(Expense $expense): Expense
    {
        $expense->loadMissing(['shopAccount', 'cheque']);

        return $this->attachPaymentAccount($expense);
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

        $totalManualPurchases = ManualTrade::sumAmount($atelierId, ManualTrade::TYPE_PURCHASE);
        $totalManualSales = ManualTrade::sumAmount($atelierId, ManualTrade::TYPE_SALE);

        return response([
            'total_expenses' => (float) $totalExpenses + $totalManualPurchases,
            'total_current_expenses' => (float) $totalCurrentExpenses + $totalManualPurchases,
            'total_capital_expenses' => (float) $totalCapitalExpenses,
            'total_manual_purchases' => $totalManualPurchases,
            'total_manual_sales' => $totalManualSales,
            'expenses_by_user' => $expensesByUser,
            'meta' => ['atelier_id' => $atelierId],
        ], 200);
    }
}

