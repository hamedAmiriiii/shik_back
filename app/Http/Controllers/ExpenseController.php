<?php

namespace App\Http\Controllers;

use App\Models\AccountingAccount;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\ManualTrade;
use App\Services\AccountingDocumentPoster;
use App\Services\ChartOfAccountsSeeder;
use App\Services\CustomerCreditExpenseService;
use App\Services\DocumentPaymentService;
use App\Services\PurchaseVatService;
use App\Services\ShopBeneficiaryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        CustomerCreditExpenseService::alignLoyaltyExpenses($atelierId);
        $query = Expense::where('atelier_id', $atelierId)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');

        if (\Illuminate\Support\Facades\Schema::hasColumn('expenses', 'accounting_account_id')) {
            $query->with('accountingAccount:id,code,name');
        }

        if ($this->supportsPaymentAccount('expenses')) {
            $query->with(['shopAccount', 'cheque']);

            // فیلتر بر اساس حساب برداشت (فروشگاه یا تنخواه)
            if ($request->filled('shop_account_id')) {
                $query->where('shop_account_id', (int) $request->input('shop_account_id'));
            }
        }

        if (DocumentPaymentService::supportsSplits()) {
            $query->with('payments.cheque');
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

        if (CustomerCreditExpenseService::supports()) {
            $source = (string) $request->input('credit_source', 'none');
            if ($source === '' ) {
                $source = 'none';
            }
            if ($source === 'none' || $source === 'without_credit') {
                $query->whereNull('credit_source');
            } elseif (in_array($source, [
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

        $needBreakdown = $request->boolean('with_payment_breakdown')
            || in_array($request->input('payment_status'), ['unpaid', 'partial'], true);
        if ($needBreakdown && DocumentPaymentService::supportsSplits()) {
            $expenses->getCollection()->transform(function (Expense $expense) {
                return $this->withAccount($expense);
            });
        }

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
            'accounting_account_id',
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
            'accounting_account_id' => 'nullable|integer',
        ], PurchaseVatService::requestRules(), $this->paymentAccountRules('expenses'), DocumentPaymentService::requestRules(), ShopBeneficiaryService::requestRules('expenses')));

        $accountLinkError = $this->applyExpenseAccount($atelierId, $fields);
        if ($accountLinkError) {
            return response()->json(['message' => $accountLinkError], 422);
        }

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

        $fields['user_name'] = $user instanceof \App\Models\User
            ? ($user->shopDisplayName() ?: trim($user->name.' '.$user->last_name))
            : trim($user->name.' '.$user->last_name);
        $fields['date'] = Carbon::now()->format('Y-m-d');
        if (!isset($fields['type']) || empty($fields['type'])) {
            $fields['type'] = 'جاری';
        }
        $fields['atelier_id'] = $atelierId;

        try {
            PurchaseVatService::applyToCreateFields($atelierId, $fields, 'expenses');
            $payable = round((float) $fields['amount'] + (float) ($fields['vat_amount'] ?? 0), 2);
            $expense = DB::transaction(function () use ($fields, $atelierId, $request, $payable) {
                $paymentSource = $fields;
                $payment = DocumentPaymentService::resolveOnCreate(
                    $atelierId,
                    $fields,
                    $payable,
                    'expenses'
                );
                DocumentPaymentService::unsetPaymentRequestFields($fields);
                $fields = array_merge($fields, $payment);

                $expense = Expense::create($fields);
                DocumentPaymentService::attachChequeFromRequest($expense, $paymentSource, $fields['user_name']);
                AccountingDocumentPoster::syncExpense($expense->fresh(['payments', 'accountingAccount']));

                return $expense;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->withAccount($expense->fresh(['shopAccount', 'cheque', 'accountingAccount'])), 201);
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
            'accounting_account_id', 'vat_mode', 'vat_percent', 'vat_amount',
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
            'accounting_account_id' => 'nullable|integer',
        ], PurchaseVatService::requestRules(), $this->paymentAccountRules('expenses'), ShopBeneficiaryService::requestRules('expenses'), DocumentPaymentService::requestRules()));

        $accountLinkError = $this->applyExpenseAccount((int) $expense->atelier_id, $fields);
        if ($accountLinkError) {
            return response()->json(['message' => $accountLinkError], 422);
        }

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
            $vatFields = array_merge([
                'vat_mode' => $expense->vat_mode ?? PurchaseVatService::MODE_NONE,
                'vat_percent' => $expense->vat_percent,
                'vat_amount' => $expense->vat_amount,
            ], array_intersect_key($fields, array_flip(['vat_mode', 'vat_percent', 'vat_amount'])));
            $vatNorm = PurchaseVatService::normalize((int) $expense->atelier_id, $newAmount, $vatFields, 'expenses');
            $fields['vat_mode'] = $vatNorm['vat_mode'];
            $fields['vat_percent'] = $vatNorm['vat_percent'];
            $fields['vat_amount'] = $vatNorm['vat_amount'];
            $newPayable = round($newAmount + (float) $vatNorm['vat_amount'], 2);
            $oldPayable = PurchaseVatService::payableAmount($expense);
            $paymentsSent = DocumentPaymentService::requestHasPaymentSplits($request);
            if (
                abs($newPayable - $oldPayable) > 0.01
                && ($expense->payment_method === DocumentPaymentService::METHOD_MIXED)
                && ! $paymentsSent
            ) {
                throw new RuntimeException('مبلغ هزینه عوض شد؛ سهم نقد، چک و نسیه را دوباره ارسال کنید.');
            }
            if ($paymentsSent) {
                $splits = DocumentPaymentService::splitsFromFields($fields, $newPayable, $expense);
                DocumentPaymentService::assertAccountSplits((int) $expense->atelier_id, $splits, $expense);
            } else {
                $accountId = $fields['shop_account_id'] ?? $expense->shop_account_id;
                if ($accountId && DocumentPaymentService::settledAmountOnAccount($expense, (int) $accountId) > 0) {
                    $debit = ($expense->payment_method === DocumentPaymentService::METHOD_MIXED)
                        ? DocumentPaymentService::settledAmountOnAccount($expense, (int) $accountId)
                        : $newPayable;
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
                if ($paymentSource) {
                    AccountingDocumentPoster::reverseExpense($expense);
                }
                $expense->update($fields);
                if ($paymentSource) {
                    DocumentPaymentService::replaceSplits($expense->fresh(), $paymentSource, (string) $expense->user_name);
                }
                AccountingDocumentPoster::syncExpense($expense->fresh(['payments']));
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

        $expense->loadMissing('payments');
        AccountingDocumentPoster::reverseExpense($expense);
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

    /**
     * مصرف اعتبار ظهرنویسی / پیش‌پرداخت ذینفع روی نسیهٔ هزینه.
     * POST /api/expenses/{expense}/apply-advance
     */
    public function applyAdvance(Request $request, Expense $expense)
    {
        $this->assertModelBelongsToStaffAtelier($request, $expense);

        $fields = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
        ]);

        try {
            $result = \App\Services\BeneficiaryAdvanceService::applyToDocument(
                $expense,
                isset($fields['amount']) ? (float) $fields['amount'] : null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'اعتبار ظهرنویسی به مبلغ '.number_format($result['applied']).' تومان روی هزینه مصرف شد.',
            'applied' => $result['applied'],
            'expense' => $this->withAccount($expense->fresh()),
        ], 200);
    }

    protected function withAccount(Expense $expense): Expense
    {
        $expense->loadMissing(['shopAccount', 'cheque']);
        if (\Illuminate\Support\Facades\Schema::hasColumn('expenses', 'accounting_account_id')) {
            $expense->loadMissing('accountingAccount:id,code,name');
        }
        if (DocumentPaymentService::supportsSplits()) {
            $expense->loadMissing(['payments.cheque', 'payments.shopAccount']);
        }

        DocumentPaymentService::alignSplitsToDocumentAmount($expense);
        $expense->loadMissing(['payments.cheque', 'payments.shopAccount']);

        $expense = ShopBeneficiaryService::attachTo($this->attachPaymentAccount($expense));
        $expense->setAttribute('payment_breakdown', DocumentPaymentService::breakdown($expense));
        if ($expense->beneficiary_id) {
            $expense->setAttribute(
                'beneficiary_advance_available',
                \App\Services\BeneficiaryAdvanceService::availableBalance(
                    (int) $expense->atelier_id,
                    (int) $expense->beneficiary_id
                )
            );
        }

        return $expense;
    }

    /**
     * وصل اختیاری هزینه به یک حساب نهایی درخت. خرید کالا پذیرفته نمی‌شود.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function applyExpenseAccount(int $atelierId, array &$fields): ?string
    {
        if (! array_key_exists('accounting_account_id', $fields)) {
            return null;
        }

        $raw = $fields['accounting_account_id'];
        if ($raw === null || $raw === '' || $raw === false) {
            $fields['accounting_account_id'] = null;

            return null;
        }

        if (! Schema::hasColumn('expenses', 'accounting_account_id')) {
            return 'ستون حساب درخت روی جدول هزینه فعال نیست. فایل SQL مربوط به accounting_account_id را اجرا کنید.';
        }

        $id = (int) $raw;
        if ($id <= 0) {
            $fields['accounting_account_id'] = null;

            return null;
        }

        $allowed = ChartOfAccountsSeeder::expenseLinkableAccounts($atelierId)
            ->pluck('id')
            ->map(fn ($rowId) => (int) $rowId)
            ->all();
        if (! in_array($id, $allowed, true)) {
            return 'این حساب برای ثبت هزینه قابل استفاده نیست.';
        }

        $fields['accounting_account_id'] = $id;

        return null;
    }

    /**
     * گزارش هزینه به تفکیک دسته‌بندی (درخت حساب).
     * پیش‌فرض بازه: ماه جاری شمسی.
     * با account_id هزینه‌های همان گره و زیرشاخه‌ها برمی‌گردد.
     */
    public function byCategory(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        CustomerCreditExpenseService::alignLoyaltyExpenses($atelierId);

        if (! AccountingAccount::tableReady()) {
            return response([
                'tree' => [],
                'total_amount' => 0,
                'total_count' => 0,
                'items' => [],
                'selected_account' => null,
                'meta' => [
                    'atelier_id' => $atelierId,
                    'filter' => $request->input('filter', 'month'),
                    'accounting_ready' => false,
                ],
            ], 200);
        }

        ChartOfAccountsSeeder::ensureForAtelier($atelierId);

        $query = Expense::where('atelier_id', $atelierId)
            ->where(function ($q) {
                $q->whereNull('type')->orWhere('type', 'جاری');
            });
        $query = CustomerCreditExpenseService::excludeFromTotals($query);

        if (! $request->has('filter')) {
            $request->merge(['filter' => 'month']);
        }
        $this->applyExpenseDateFilter($query, $request);

        if (Schema::hasColumn('expenses', 'accounting_account_id')) {
            $query->with('accountingAccount:id,code,name');
        }

        $expenses = $query->orderBy('date', 'desc')->orderBy('id', 'desc')->get();

        $invoiceQuery = Invoice::where('atelier_id', $atelierId);
        $this->applyExpenseDateFilter($invoiceQuery, $request);
        $invoices = $invoiceQuery->orderBy('date', 'desc')->orderBy('id', 'desc')->get();

        $accounts = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->active()
            ->orderBy('code')
            ->get();
        $byId = $accounts->keyBy(fn (AccountingAccount $row) => (int) $row->id);
        $byCode = $accounts->keyBy('code');
        $allowedIds = ChartOfAccountsSeeder::expenseLinkableAccounts($atelierId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $allowedLookup = array_fill_keys($allowedIds, true);

        $payrollExpenseIds = [];
        if (Schema::hasTable('employee_payroll_payments') && $expenses->isNotEmpty()) {
            $payrollExpenseIds = DB::table('employee_payroll_payments')
                ->whereIn('expense_id', $expenses->pluck('id')->all())
                ->whereIn('payment_type', ['salary', 'advance'])
                ->pluck('expense_id')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all();
        }

        $directAmount = [];
        $directCount = [];
        $resolvedByExpenseId = [];

        foreach ($expenses as $expense) {
            $accountId = $this->resolveExpenseCategoryAccountId(
                $expense,
                $allowedLookup,
                $byCode,
                $payrollExpenseIds
            );
            if ($accountId <= 0) {
                continue;
            }
            $resolvedByExpenseId[(int) $expense->id] = $accountId;
            $directAmount[$accountId] = ($directAmount[$accountId] ?? 0) + (float) $expense->amount;
            $directCount[$accountId] = ($directCount[$accountId] ?? 0) + 1;
        }

        $purchaseAccount = $byCode->get(ChartOfAccountsSeeder::CODE_INV_CATALOG);
        $purchaseAccountId = $purchaseAccount ? (int) $purchaseAccount->id : 0;
        $resolvedByInvoiceId = [];
        foreach ($invoices as $invoice) {
            if ($purchaseAccountId <= 0) {
                break;
            }
            $resolvedByInvoiceId[(int) $invoice->id] = $purchaseAccountId;
            $directAmount[$purchaseAccountId] = ($directAmount[$purchaseAccountId] ?? 0) + (float) $invoice->amount;
            $directCount[$purchaseAccountId] = ($directCount[$purchaseAccountId] ?? 0) + 1;
        }

        $childrenMap = [];
        foreach ($accounts as $account) {
            $parentId = $account->parent_id ? (int) $account->parent_id : 0;
            $childrenMap[$parentId][] = (int) $account->id;
        }

        $selectedAccountId = $request->filled('account_id') ? (int) $request->input('account_id') : 0;
        $selectedDescendantIds = [];
        if ($selectedAccountId > 0 && isset($byId[$selectedAccountId])) {
            $selectedDescendantIds = $this->collectAccountDescendantIds($selectedAccountId, $childrenMap);
            $selectedDescendantIds[$selectedAccountId] = true;
        }

        $items = [];
        foreach ($expenses as $expense) {
            $resolvedId = $resolvedByExpenseId[(int) $expense->id] ?? 0;
            if ($resolvedId <= 0) {
                continue;
            }
            if ($selectedAccountId > 0 && ! isset($selectedDescendantIds[$resolvedId])) {
                continue;
            }
            $account = $byId[$resolvedId] ?? null;
            $items[] = [
                'id' => (int) $expense->id,
                'source' => 'expense',
                'title' => $expense->title,
                'amount' => (float) $expense->amount,
                'type' => $expense->type,
                'date' => $expense->date,
                'user_name' => $expense->user_name,
                'accounting_account_id' => $resolvedId,
                'accounting_account' => $account ? [
                    'id' => (int) $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                ] : null,
            ];
        }
        foreach ($invoices as $invoice) {
            $resolvedId = $resolvedByInvoiceId[(int) $invoice->id] ?? 0;
            if ($resolvedId <= 0) {
                continue;
            }
            if ($selectedAccountId > 0 && ! isset($selectedDescendantIds[$resolvedId])) {
                continue;
            }
            $account = $byId[$resolvedId] ?? null;
            $items[] = [
                'id' => (int) $invoice->id,
                'source' => 'invoice',
                'title' => $invoice->title ?: ('فاکتور #'.$invoice->id),
                'amount' => (float) $invoice->amount,
                'type' => 'فاکتور',
                'date' => $invoice->date,
                'user_name' => $invoice->user_name,
                'accounting_account_id' => $resolvedId,
                'accounting_account' => $account ? [
                    'id' => (int) $account->id,
                    'code' => $account->code,
                    'name' => 'فاکتور خرید',
                ] : [
                    'id' => 0,
                    'code' => ChartOfAccountsSeeder::CODE_INV_CATALOG,
                    'name' => 'فاکتور خرید',
                ],
            ];
        }

        usort($items, function (array $a, array $b) {
            $dateCmp = strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });

        $tree = [];
        foreach ($accounts->filter(fn (AccountingAccount $row) => $row->parent_id === null) as $root) {
            if (! $this->isExpenseCategoryRoot($root)) {
                continue;
            }
            $node = $this->buildExpenseCategoryTreeNode(
                $root,
                $childrenMap,
                $byId,
                $directAmount,
                $directCount
            );
            if ($node !== null) {
                $tree[] = $node;
            }
        }

        $totalAmount = array_sum($directAmount);
        $totalCount = array_sum($directCount);
        $selectedAccount = null;
        if ($selectedAccountId > 0 && isset($byId[$selectedAccountId])) {
            $acc = $byId[$selectedAccountId];
            $selectedAccount = [
                'id' => (int) $acc->id,
                'code' => $acc->code,
                'name' => $acc->name,
                'amount' => array_sum(array_map(
                    fn ($id) => $directAmount[$id] ?? 0,
                    array_keys($selectedDescendantIds)
                )),
                'count' => array_sum(array_map(
                    fn ($id) => $directCount[$id] ?? 0,
                    array_keys($selectedDescendantIds)
                )),
            ];
        }

        return response([
            'tree' => $tree,
            'total_amount' => (float) $totalAmount,
            'total_count' => (int) $totalCount,
            'items' => $items,
            'selected_account' => $selectedAccount,
            'meta' => [
                'atelier_id' => $atelierId,
                'filter' => $request->input('filter', 'month'),
                'type' => 'جاری',
                'accounting_ready' => true,
            ],
        ], 200);
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

    /**
     * فیلتر تاریخ هزینه‌ها (شمسی). پیش‌فرض در byCategory ماه جاری است.
     */
    protected function applyExpenseDateFilter($query, Request $request): void
    {
        if (! $request->has('filter')) {
            return;
        }

        if ($request->filter === 'today') {
            $query->whereDate('date', Carbon::today());
        } elseif ($request->filter === 'week') {
            $now = Jalalian::now();
            $dayOfWeek = $now->getDayOfWeek();
            $startOfWeek = Jalalian::now()->subDays($dayOfWeek)->toCarbon()->startOfDay();
            $endOfWeek = Jalalian::now()->addDays(6 - $dayOfWeek)->toCarbon()->endOfDay();
            $query->whereBetween('date', [$startOfWeek, $endOfWeek]);
        } elseif ($request->filter === 'month') {
            $now = Jalalian::now();
            $startOfMonth = (new Jalalian($now->getYear(), $now->getMonth(), 1))->toCarbon()->startOfDay();
            $endOfMonth = (new Jalalian($now->getYear(), $now->getMonth(), 1))->addMonths(1)->subDays(1)->toCarbon()->endOfDay();
            $query->whereBetween('date', [$startOfMonth, $endOfMonth]);
        } elseif ($request->filter === 'year') {
            $year = Jalalian::now()->getYear();
            $startOfYear = (new Jalalian($year, 1, 1))->toCarbon()->startOfDay();
            $endOfYear = (new Jalalian($year, 12, 29))->toCarbon()->endOfDay();
            $query->whereBetween('date', [$startOfYear, $endOfYear]);
        } elseif ($request->filter === 'range') {
            if ($request->has('from_date')) {
                $fromDate = json_decode($request->input('from_date'));
                if (is_object($fromDate) && isset($fromDate->year, $fromDate->month, $fromDate->day)) {
                    $fromCarbon = (new Jalalian($fromDate->year, $fromDate->month, $fromDate->day))->toCarbon()->startOfDay();
                    $query->where('date', '>=', $fromCarbon);
                }
            }
            if ($request->has('to_date')) {
                $toDate = json_decode($request->input('to_date'));
                if (is_object($toDate) && isset($toDate->year, $toDate->month, $toDate->day)) {
                    $toCarbon = (new Jalalian($toDate->year, $toDate->month, $toDate->day))->toCarbon()->endOfDay();
                    $query->where('date', '<=', $toCarbon);
                }
            }
        }
    }

    /**
     * حساب مؤثر دسته برای گزارش (لینک مستقیم یا fallback نوع/عنوان).
     *
     * @param  array<int, true>  $allowedLookup
     * @param  \Illuminate\Support\Collection<string, AccountingAccount>  $byCode
     * @param  array<int, mixed>  $payrollExpenseIds
     */
    protected function resolveExpenseCategoryAccountId(
        Expense $expense,
        array $allowedLookup,
        $byCode,
        array $payrollExpenseIds
    ): int {
        if (Schema::hasColumn('expenses', 'accounting_account_id')) {
            $linkedId = (int) ($expense->accounting_account_id ?? 0);
            if ($linkedId > 0 && isset($allowedLookup[$linkedId])) {
                return $linkedId;
            }
        }

        $code = ChartOfAccountsSeeder::CODE_EXPENSE;
        if (($expense->type ?? '') === 'سرمایه') {
            $code = ChartOfAccountsSeeder::CODE_CAPEX;
        } elseif (isset($payrollExpenseIds[(int) $expense->id])) {
            $code = ChartOfAccountsSeeder::CODE_PAYROLL;
        } else {
            $title = (string) ($expense->title ?? '');
            if ($title !== '' && preg_match('/پرداخت حقوق|مساعده/u', $title)) {
                $code = ChartOfAccountsSeeder::CODE_PAYROLL;
            }
        }

        $account = $byCode->get($code);

        return $account ? (int) $account->id : 0;
    }

    protected function isExpenseCategoryRoot(AccountingAccount $root): bool
    {
        if ($root->kind === AccountingAccount::KIND_EXPENSE) {
            return true;
        }

        return strpos((string) $root->code, '1') === 0;
    }

    /**
     * @param  array<int, array<int, int>>  $childrenMap
     * @return array<int, true>
     */
    protected function collectAccountDescendantIds(int $accountId, array $childrenMap): array
    {
        $ids = [];
        $stack = $childrenMap[$accountId] ?? [];
        while (! empty($stack)) {
            $childId = (int) array_pop($stack);
            if (isset($ids[$childId])) {
                continue;
            }
            $ids[$childId] = true;
            foreach ($childrenMap[$childId] ?? [] as $grandChildId) {
                $stack[] = (int) $grandChildId;
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, array<int, int>>  $childrenMap
     * @param  \Illuminate\Support\Collection<int, AccountingAccount>  $byId
     * @param  array<int, float>  $directAmount
     * @param  array<int, int>  $directCount
     * @return array<string, mixed>|null
     */
    protected function buildExpenseCategoryTreeNode(
        AccountingAccount $account,
        array $childrenMap,
        $byId,
        array $directAmount,
        array $directCount
    ): ?array {
        $id = (int) $account->id;
        $code = (string) $account->code;
        $isPurchasePath = $code === '11' || $code === '113' || strpos($code, '113') === 0;
        $isExpenseBranch = $account->kind === AccountingAccount::KIND_EXPENSE
            || strpos($code, '121') === 0
            || $code === '12'
            || $code === '1'
            || $isPurchasePath;

        if (! $isExpenseBranch) {
            return null;
        }

        $children = [];
        $rolledAmount = (float) ($directAmount[$id] ?? 0);
        $rolledCount = (int) ($directCount[$id] ?? 0);

        foreach ($childrenMap[$id] ?? [] as $childId) {
            $child = $byId->get((int) $childId);
            if (! $child) {
                continue;
            }
            $childNode = $this->buildExpenseCategoryTreeNode(
                $child,
                $childrenMap,
                $byId,
                $directAmount,
                $directCount
            );
            if ($childNode === null) {
                continue;
            }
            $rolledAmount += (float) $childNode['amount'];
            $rolledCount += (int) $childNode['count'];
            $children[] = $childNode;
        }

        $isAssetCapexPath = in_array($code, ['1', '12', '121'], true) || strpos($code, '121') === 0;
        // شاخهٔ دارایی / خرید کالا فقط وقتی مبلغ دارد؛ درخت هزینه‌ها همیشه کامل است
        if (($isAssetCapexPath || $isPurchasePath) && $account->kind !== AccountingAccount::KIND_EXPENSE) {
            if ($rolledAmount <= 0 && empty($children)) {
                return null;
            }
        }

        $displayName = $account->name;
        if ($code === ChartOfAccountsSeeder::CODE_INV_CATALOG) {
            $displayName = 'فاکتور خرید';
        }

        return [
            'id' => $id,
            'parent_id' => $account->parent_id ? (int) $account->parent_id : null,
            'code' => $account->code,
            'name' => $displayName,
            'level' => $account->level,
            'kind' => $account->kind,
            'amount' => $rolledAmount,
            'count' => $rolledCount,
            'direct_amount' => (float) ($directAmount[$id] ?? 0),
            'direct_count' => (int) ($directCount[$id] ?? 0),
            'children' => $children,
        ];
    }
}

