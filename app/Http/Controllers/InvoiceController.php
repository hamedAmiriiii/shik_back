<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\DocumentPaymentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;
use RuntimeException;

class InvoiceController extends Controller
{
    /**
     * نمایش لیست فاکتورها (همان فروشگاه)
     * GET /api/invoices?per_page=20&page=1
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $query = Invoice::query()
            ->where(function ($q) use ($atelierId) {
                $q->where('atelier_id', $atelierId)
                    ->orWhereNull('atelier_id');
            })
            ->orderBy('id', 'desc');

        if ($this->supportsPaymentAccount('invoices')) {
            $query->with(['shopAccount', 'cheque']);

            // فیلتر بر اساس حساب پرداخت (فروشگاه یا تنخواه)
            if ($request->filled('shop_account_id')) {
                $query->where('shop_account_id', (int) $request->input('shop_account_id'));
            }
        }

        if (DocumentPaymentService::supports(new Invoice()) && $request->filled('payment_method') && in_array($request->input('payment_method'), DocumentPaymentService::methods(), true)) {
            $query->where('payment_method', $request->input('payment_method'));
        }
        if (DocumentPaymentService::supports(new Invoice()) && $request->filled('payment_status') && in_array($request->input('payment_status'), ['paid', 'unpaid'], true)) {
            $query->where('payment_status', $request->input('payment_status'));
        }

        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    if (isset($searchDataModel->title)) {
                        $q->where('title', 'like', '%'.$searchDataModel->title.'%');
                    }
                    if (isset($searchDataModel->description)) {
                        $q->orWhere('description', 'like', '%'.$searchDataModel->description.'%');
                    }
                    if (isset($searchDataModel->user_name)) {
                        $q->orWhere('user_name', 'like', '%'.$searchDataModel->user_name.'%');
                    }
                } elseif (is_string($searchDataModel)) {
                    $q->where('title', 'like', '%'.$searchDataModel.'%')
                        ->orWhere('description', 'like', '%'.$searchDataModel.'%')
                        ->orWhere('user_name', 'like', '%'.$searchDataModel.'%');
                }
            });
        }

        // فیلتر تاریخ
        if ($request->has('filter')) {
            if ($request->filter === 'today') {
                $query->whereDate('date', Carbon::today());
            } elseif ($request->filter === 'week') {
                $now = Jalalian::now();
                $dayOfWeek = $now->getDayOfWeek();
                $startOfWeekJalali = Jalalian::now()->subDays($dayOfWeek);
                $endOfWeekJalali = Jalalian::now()->addDays(6 - $dayOfWeek);
                $startOfWeek = $startOfWeekJalali->toCarbon()->startOfDay();
                $endOfWeek = $endOfWeekJalali->toCarbon()->endOfDay();
                $query->whereBetween('date', [$startOfWeek, $endOfWeek]);
            } elseif ($request->filter === 'month') {
                $now = Jalalian::now();
                $year = $now->getYear();
                $month = $now->getMonth();
                $startOfMonthJalali = new Jalalian($year, $month, 1);
                $startOfMonth = $startOfMonthJalali->toCarbon()->startOfDay();
                $endOfMonthJalali = (new Jalalian($year, $month, 1))->addMonths(1)->subDays(1);
                $endOfMonth = $endOfMonthJalali->toCarbon()->endOfDay();
                $query->whereBetween('date', [$startOfMonth, $endOfMonth]);
            } elseif ($request->filter === 'year') {
                $now = Jalalian::now();
                $year = $now->getYear();
                $startOfYear = (new Jalalian($year, 1, 1))->toCarbon()->startOfDay();
                $endOfYear = (new Jalalian($year, 12, 29))->toCarbon()->endOfDay();
                $query->whereBetween('date', [$startOfYear, $endOfYear]);
            } elseif ($request->filter === 'range') {
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

        // جمع کل مبالغ فاکتورهای فیلترشده (قبل از paginate)
        $totalAmount = (clone $query)->sum('amount');

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $invoices = $query->paginate($perPage, ['*'], 'page', $page);
        $invoices->appends($request->except('page'));

        $invoicesArray = $invoices->toArray();
        $invoicesArray['total_amount'] = (float) $totalAmount;

        return response($invoicesArray, 200);
    }

    /**
     * افزودن فاکتور جدید
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت فاکتور فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $fields = $request->validate(array_merge([
            'amount' => 'required|numeric|min:0',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ], $this->paymentAccountRules('invoices'), DocumentPaymentService::requestRules()));

        $accountError = $this->paymentAccountError($atelierId, $fields['shop_account_id'] ?? null);
        if ($accountError) {
            return response()->json(['message' => $accountError], 422);
        }

        $user = $this->shopRequestActor($request);
        if (! $user) {
            return response(['error' => 'کاربر احراز هویت نشده است'], 401);
        }

        $fields['user_name'] = trim($user->name.' '.$user->last_name);
        $fields['date'] = Carbon::now()->format('Y-m-d');
        $fields['atelier_id'] = $atelierId;

        try {
            $invoice = DB::transaction(function () use ($fields, $atelierId) {
                $payment = DocumentPaymentService::resolveOnCreate(
                    $atelierId,
                    $fields,
                    (float) $fields['amount'],
                    'invoices'
                );
                $chequePayload = [
                    'cheque' => $fields['cheque'] ?? null,
                    'cheque_id' => $fields['cheque_id'] ?? null,
                    'payment_method' => $payment['payment_method'] ?? ($fields['payment_method'] ?? null),
                ];
                unset($fields['cheque'], $fields['cheque_id'], $fields['payment_method']);
                $fields = array_merge($fields, $payment);

                $invoice = Invoice::create($fields);
                DocumentPaymentService::attachChequeFromRequest($invoice, $chequePayload, $fields['user_name']);

                return $invoice;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->withAccount($invoice->fresh(['shopAccount', 'cheque'])), 201);
    }

    /**
     * نمایش جزئیات یک فاکتور
     */
    public function show(Request $request, Invoice $invoice)
    {
        $this->assertModelBelongsToStaffAtelier($request, $invoice);

        return response($this->withAccount($invoice), 200);
    }

    /**
     * ویرایش اطلاعات فاکتور
     */
    public function update(Request $request, Invoice $invoice)
    {
        $this->assertModelBelongsToStaffAtelier($request, $invoice);

        $fields = $request->validate(array_merge([
            'amount' => 'sometimes|required|numeric|min:0',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'date' => 'sometimes|required|date',
        ], $this->paymentAccountRules('invoices')));

        if (array_key_exists('shop_account_id', $fields)) {
            $accountError = $this->paymentAccountError((int) $invoice->atelier_id, $fields['shop_account_id']);
            if ($accountError) {
                return response()->json(['message' => $accountError], 422);
            }
        }

        try {
            $newAmount = (float) ($fields['amount'] ?? $invoice->amount);
            $accountId = $fields['shop_account_id'] ?? $invoice->shop_account_id;
            if (DocumentPaymentService::isPaid($invoice) && $accountId) {
                DocumentPaymentService::assertCanDebit(
                    (int) $invoice->atelier_id,
                    (int) $accountId,
                    $newAmount,
                    $invoice
                );
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $invoice->update($fields);

        return response($this->withAccount($invoice->fresh()), 200);
    }

    /**
     * حذف فاکتور
     */
    public function destroy(Request $request, Invoice $invoice)
    {
        $this->assertModelBelongsToStaffAtelier($request, $invoice);

        if ($invoice->cheque && $invoice->cheque->status === \App\Models\Cheque::STATUS_CLEARED) {
            return response()->json([
                'message' => 'این فاکتور با چک وصول‌شده ثبت شده و قابل حذف نیست.',
            ], 422);
        }

        if ($invoice->cheque && $invoice->cheque->status === \App\Models\Cheque::STATUS_PENDING) {
            $invoice->cheque->update(['status' => \App\Models\Cheque::STATUS_CANCELLED, 'invoice_id' => null]);
        }

        $invoice->delete();

        return response(['message' => 'فاکتور با موفقیت حذف شد'], 200);
    }

    /**
     * تسویه نسیه/چک از حساب — فقط اگر موجودی کافی باشد.
     * POST /api/invoices/{invoice}/settle  { "shop_account_id": 1 }
     */
    public function settle(Request $request, Invoice $invoice)
    {
        $this->assertModelBelongsToStaffAtelier($request, $invoice);

        $fields = $request->validate([
            'shop_account_id' => 'required|integer|exists:shop_accounts,id',
        ]);

        $accountError = $this->paymentAccountError((int) $invoice->atelier_id, $fields['shop_account_id']);
        if ($accountError) {
            return response()->json(['message' => $accountError], 422);
        }

        try {
            $invoice = DocumentPaymentService::settle($invoice, (int) $fields['shop_account_id']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->withAccount($invoice), 200);
    }

    protected function withAccount(Invoice $invoice): Invoice
    {
        $invoice->loadMissing(['shopAccount', 'cheque']);

        return $this->attachPaymentAccount($invoice);
    }
}
