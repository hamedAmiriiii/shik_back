<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ManualTrade;
use App\Services\CustomerCreditExpenseService;
use App\Services\DocumentPaymentService;
use App\Services\ShopBeneficiaryService;
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

        if (DocumentPaymentService::supportsSplits()) {
            $query->with('payments');
        }

        if (ShopBeneficiaryService::supports('expenses')) {
            $query->with(['beneficiary:id,phone,name']);
            $beneficiaryId = $request->input('beneficiary_id');
            if ($beneficiaryId === null || $beneficiaryId === '') {
                $searchModel = json_decode($request->input('searchFilterModel'));
                if (is_object($searchModel) && isset($searchModel->beneficiary_id)) {
                    $beneficiaryId = $searchModel->beneficiary_id;
                }
            }
            if ($beneficiaryId !== null && $beneficiaryId !== '') {
                $query->where('beneficiary_id', (int) $beneficiaryId);
            }
        }

        if (DocumentPaymentService::supports(new Expense()) && $request->filled('payment_method')) {
            $method = DocumentPaymentService::normalizeMethod($request->input('payment_method'))
                ?: $request->input('payment_method');
            if (in_array($method, DocumentPaymentService::methods(), true)) {
                $query->where(function ($q) use ($method) {
                    $q->where('payment_method', $method);
                    if (DocumentPaymentService::supportsSplits() && $method !== DocumentPaymentService::METHOD_MIXED) {
                        $q->orWhereHas('payments', function ($p) use ($method) {
                            $p->where('method', $method);
                        });
                    }
                });
            }
        }
        if (DocumentPaymentService::supports(new Expense()) && $request->filled('payment_status') && in_array($request->input('payment_status'), ['paid', 'unpaid', 'partial'], true)) {
            $query->where('payment_status', $request->input('payment_status'));
        }

        if (CustomerCreditExpenseService::supports() && $request->filled('credit_source')) {
            $source = (string) $request->input('credit_source');
            if (in_array($source, [
                CustomerCreditExpenseService::SOURCE_LOYALTY,
                CustomerCreditExpenseService::SOURCE_RETURN,
                CustomerCreditExpenseService::SOURCE_MANUAL,
            ], true)) {
                $query->where('credit_source', $source);
            }
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

        $this->mergeRequestPayload($request, [
            'amount', 'title', 'type', 'shop_account_id', 'payment_method', 'cheque', 'cheque_id',
            'payments', 'cash_amount', 'cheque_amount', 'credit_amount', 'beneficiary_id', 'user_shiksho_id',
        ]);
        $paymentsRaw = $request->input('payments');
        if (is_string($paymentsRaw) && $paymentsRaw !== '') {
            $decoded = json_decode($paymentsRaw, true);
            if (is_array($decoded)) {
                $request->merge(['payments' => $decoded]);
            }
        }

        $fields = $request->validate(array_merge([
            'amount' => 'required|numeric|min:0',
            'title' => 'required|string|max:255',
            'type' => 'nullable|in:جاری,سرمایه',
        ], $this->paymentAccountRules('expenses'), DocumentPaymentService::requestRules(), ShopBeneficiaryService::requestRules('expenses')));

        $beneficiaryError = ShopBeneficiaryService::applyToFields($atelierId, $fields, false, 'expenses');
        if ($beneficiaryError) {
            return response()->json(['message' => $beneficiaryError], 422);
        }

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
                $paymentSource = $fields;
                $payment = DocumentPaymentService::resolveOnCreate(
                    $atelierId,
                    $fields,
                    (float) $fields['amount'],
                    'expenses'
                );
                DocumentPaymentService::unsetPaymentRequestFields($fields);
                $fields = array_merge($fields, $payment);

                $expense = Expense::create($fields);
                DocumentPaymentService::attachChequeFromRequest($expense, $paymentSource, $fields['user_name']);

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

        $this->mergeRequestPayload($request, [
            'user_name', 'amount', 'title', 'type', 'shop_account_id', 'payment_method', 'cheque', 'cheque_id',
            'payments', 'cash_amount', 'cheque_amount', 'credit_amount', 'beneficiary_id', 'user_shiksho_id',
        ]);
        $paymentsRaw = $request->input('payments');
        if (is_string($paymentsRaw) && $paymentsRaw !== '') {
            $decoded = json_decode($paymentsRaw, true);
            if (is_array($decoded)) {
                $request->merge(['payments' => $decoded]);
            }
        }

        $fields = $request->validate(array_merge([
            'user_name' => 'sometimes|required|string',
            'amount' => 'sometimes|required|numeric|min:0',
            'title' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:جاری,سرمایه',
        ], $this->paymentAccountRules('expenses'), ShopBeneficiaryService::requestRules('expenses'), DocumentPaymentService::requestRules()));

        $beneficiaryError = ShopBeneficiaryService::applyToFields((int) $expense->atelier_id, $fields, true, 'expenses');
        if ($beneficiaryError) {
            return response()->json(['message' => $beneficiaryError], 422);
        }

        if (array_key_exists('shop_account_id', $fields)) {
            $accountError = $this->paymentAccountError((int) $expense->atelier_id, $fields['shop_account_id']);
            if ($accountError) {
                return response()->json(['message' => $accountError], 422);
            }
        }

        try {
            $newAmount = (float) ($fields['amount'] ?? $expense->amount);
            $paymentsSent = DocumentPaymentService::requestHasPaymentSplits($request);
            if (
                abs($newAmount - (float) $expense->amount) > 0.01
                && ($expense->payment_method === DocumentPaymentService::METHOD_MIXED)
                && ! $paymentsSent
            ) {
                throw new RuntimeException('مبلغ هزینه عوض شد؛ سهم نقد، چک و نسیه را دوباره ارسال کنید.');
            }
            if ($paymentsSent) {
                $splits = DocumentPaymentService::splitsFromFields($fields, $newAmount, $expense);
                DocumentPaymentService::assertAccountSplits((int) $expense->atelier_id, $splits, $expense);
            } else {
                $accountId = $fields['shop_account_id'] ?? $expense->shop_account_id;
                if ($accountId && DocumentPaymentService::settledAmountOnAccount($expense, (int) $accountId) > 0) {
                    $debit = ($expense->payment_method === DocumentPaymentService::METHOD_MIXED)
                        ? DocumentPaymentService::settledAmountOnAccount($expense, (int) $accountId)
                        : $newAmount;
                    DocumentPaymentService::assertCanDebit(
                        (int) $expense->atelier_id,
                        (int) $accountId,
                        $debit,
                        $expense
                    );
                }
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            DB::transaction(function () use ($expense, $fields, $request) {
                $paymentSource = DocumentPaymentService::requestHasPaymentSplits($request) ? $fields : null;
                DocumentPaymentService::unsetPaymentRequestFields($fields);
                $expense->update($fields);
                if ($paymentSource) {
                    DocumentPaymentService::replaceSplits($expense->fresh(), $paymentSource, (string) $expense->user_name);
                }
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->withAccount($expense->fresh()), 200);
    }

    /**
     * حذف هزینه
     */
    public function destroy(Request $request, Expense $expense)
    {
        $this->assertModelBelongsToStaffAtelier($request, $expense);

        $clearedCheque = \App\Models\Cheque::query()
            ->where('expense_id', $expense->id)
            ->where('status', \App\Models\Cheque::STATUS_CLEARED)
            ->exists();
        if ($clearedCheque || ($expense->cheque && $expense->cheque->status === \App\Models\Cheque::STATUS_CLEARED)) {
            return response()->json([
                'message' => 'این هزینه با چک وصول‌شده ثبت شده و قابل حذف نیست.',
            ], 422);
        }

        $pendingCheques = \App\Models\Cheque::query()
            ->where('expense_id', $expense->id)
            ->where('status', \App\Models\Cheque::STATUS_PENDING)
            ->get();
        foreach ($pendingCheques as $cheque) {
            $cheque->update(['status' => \App\Models\Cheque::STATUS_CANCELLED, 'expense_id' => null]);
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
            'amount' => 'nullable|numeric|min:0.01',
        ]);

        $accountError = $this->paymentAccountError((int) $expense->atelier_id, $fields['shop_account_id']);
        if ($accountError) {
            return response()->json(['message' => $accountError], 422);
        }

        try {
            $expense = DocumentPaymentService::settle(
                $expense,
                (int) $fields['shop_account_id'],
                isset($fields['amount']) ? (float) $fields['amount'] : null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->withAccount($expense), 200);
    }

    protected function withAccount(Expense $expense): Expense
    {
        $expense->loadMissing(['shopAccount', 'cheque']);
        if (DocumentPaymentService::supportsSplits()) {
            $expense->loadMissing(['payments.cheque', 'payments.shopAccount']);
        }

        $expense = ShopBeneficiaryService::attachTo($this->attachPaymentAccount($expense));
        $expense->setAttribute('payment_breakdown', DocumentPaymentService::breakdown($expense));

        return $expense;
    }

    /**
     * آمار کلی هزینه‌ها
     */
    public function statistics(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $allQuery = Expense::where('atelier_id', $atelierId);
        $expenseQuery = CustomerCreditExpenseService::excludeFromTotals(clone $allQuery);

        $totalExpenses = (clone $expenseQuery)->sum('amount');

        $totalCurrentExpenses = (clone $expenseQuery)->where('type', 'جاری')->sum('amount');

        $totalCapitalExpenses = (clone $expenseQuery)->where('type', 'سرمایه')->sum('amount');

        $expensesByUser = (clone $expenseQuery)->select(
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
            'customer_credit_expenses' => CustomerCreditExpenseService::sumForAtelier($atelierId),
            'customer_credit_loyalty' => CustomerCreditExpenseService::sumForAtelier(
                $atelierId,
                CustomerCreditExpenseService::SOURCE_LOYALTY
            ),
            'customer_credit_returns' => CustomerCreditExpenseService::sumForAtelier(
                $atelierId,
                CustomerCreditExpenseService::SOURCE_RETURN
            ),
            'customer_credit_manual' => CustomerCreditExpenseService::sumForAtelier(
                $atelierId,
                CustomerCreditExpenseService::SOURCE_MANUAL
            ),
            'expenses_by_user' => $expensesByUser,
            'meta' => ['atelier_id' => $atelierId],
        ], 200);
    }
}

