<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\DocumentPaymentService;
use App\Services\ShopBeneficiaryService;
use App\Tools\ImageTools;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

        if ($this->supportsInvoiceItems()) {
            $query->with('items');
        }

        if (ShopBeneficiaryService::supports('invoices')) {
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

        if ($this->supportsPaymentAccount('invoices')) {
            $query->with(['shopAccount', 'cheque']);

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

        $this->prepareInvoiceRequest($request);

        $itemRows = $this->normalizedItemsFromRequest($request);
        if ($itemRows === null) {
            return response()->json(['message' => 'فرمت آیتم‌های فاکتور نامعتبر است.'], 422);
        }
        $itemsSent = $this->itemsWereSent($request);

        $fields = $request->validate(array_merge([
            'amount' => ($itemsSent && $itemRows !== [] ? 'nullable' : 'required').'|numeric|min:0',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable',
            'image_base64' => 'nullable|string',
        ], $this->paymentAccountRules('invoices'), DocumentPaymentService::requestRules(), ShopBeneficiaryService::requestRules('invoices')));

        $amountConflict = $this->amountConflictWithItems($request, $itemRows);
        if ($amountConflict !== null) {
            return response()->json(['message' => $amountConflict], 422);
        }

        if ($itemRows !== []) {
            $fields['amount'] = $this->sumItemTotals($itemRows);
        }

        $beneficiaryError = ShopBeneficiaryService::applyToFields($atelierId, $fields, false, 'invoices');
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

        $fields['user_name'] = trim($user->name.' '.$user->last_name);
        $fields['date'] = Carbon::now()->format('Y-m-d');
        $fields['atelier_id'] = $atelierId;

        try {
            $invoice = DB::transaction(function () use ($fields, $atelierId, $itemRows, $request) {
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
                unset($fields['cheque'], $fields['cheque_id'], $fields['payment_method'], $fields['image'], $fields['image_base64']);
                $fields = array_merge($fields, $payment);

                $invoice = Invoice::create($fields);
                $this->syncInvoiceItems($invoice, $itemRows);
                DocumentPaymentService::attachChequeFromRequest($invoice, $chequePayload, $fields['user_name']);
                $this->saveInvoiceImageFromRequest($invoice, $request);

                return $invoice;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->withDetails($invoice->fresh()), 201);
    }

    /**
     * نمایش جزئیات یک فاکتور
     */
    public function show(Request $request, Invoice $invoice)
    {
        $this->assertModelBelongsToStaffAtelier($request, $invoice);

        return response($this->withDetails($invoice), 200);
    }

    /**
     * ویرایش اطلاعات فاکتور
     */
    public function update(Request $request, Invoice $invoice)
    {
        $this->assertModelBelongsToStaffAtelier($request, $invoice);

        if ($this->supportsInvoiceItems()) {
            $invoice->loadMissing('items');
        }

        $this->prepareInvoiceRequest($request);

        $itemRows = $this->normalizedItemsFromRequest($request);
        if ($itemRows === null) {
            return response()->json(['message' => 'فرمت آیتم‌های فاکتور نامعتبر است.'], 422);
        }
        $itemsSent = $this->itemsWereSent($request);

        $fields = $request->validate(array_merge([
            'amount' => 'sometimes|nullable|numeric|min:0',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'date' => 'sometimes|required|date',
            'image' => 'nullable',
            'image_base64' => 'nullable|string',
            'remove_image' => 'nullable|boolean',
        ], $this->paymentAccountRules('invoices'), ShopBeneficiaryService::requestRules('invoices')));

        $beneficiaryError = ShopBeneficiaryService::applyToFields((int) $invoice->atelier_id, $fields, true, 'invoices');
        if ($beneficiaryError) {
            return response()->json(['message' => $beneficiaryError], 422);
        }

        $existingItemRows = $invoice->hasLineItems()
            ? $invoice->items->map(function (InvoiceItem $item) {
                return ['total' => (float) $item->total];
            })->all()
            : [];

        if ($itemsSent) {
            if ($itemRows !== []) {
                $amountConflict = $this->amountConflictWithItems($request, $itemRows);
                if ($amountConflict !== null) {
                    return response()->json(['message' => $amountConflict], 422);
                }
                $fields['amount'] = $this->sumItemTotals($itemRows);
            } elseif (! $this->requestSentAmount($request)) {
                unset($fields['amount']);
            }
        } elseif ($invoice->hasLineItems()) {
            if ($this->requestSentAmount($request)) {
                $itemsSum = $this->sumItemTotals($existingItemRows);
                if (abs(round((float) $request->input('amount'), 2) - $itemsSum) > 0.001) {
                    return response()->json([
                        'message' => $this->amountWithItemsMessage($itemsSum, $request->input('amount')),
                    ], 422);
                }
            }
            unset($fields['amount']);
        }

        if (array_key_exists('shop_account_id', $fields)) {
            $accountError = $this->paymentAccountError((int) $invoice->atelier_id, $fields['shop_account_id']);
            if ($accountError) {
                return response()->json(['message' => $accountError], 422);
            }
        }

        try {
            $newAmount = (float) ($fields['amount'] ?? $invoice->amount);
            if ($itemsSent && $itemRows !== []) {
                $newAmount = $this->sumItemTotals($itemRows);
            } elseif (! $itemsSent && $invoice->hasLineItems()) {
                $newAmount = $invoice->itemsSum();
            }
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

        try {
            DB::transaction(function () use ($invoice, $fields, $itemRows, $itemsSent, $request) {
                unset($fields['image'], $fields['image_base64'], $fields['remove_image']);
                $invoice->update($fields);
                if ($itemsSent) {
                    $this->syncInvoiceItems($invoice, $itemRows);
                }
                if ($request->boolean('remove_image') && ! $this->requestHasImage($request)) {
                    $this->deleteInvoiceImage($invoice);
                    $invoice->update(['image_path' => null]);
                } else {
                    $this->saveInvoiceImageFromRequest($invoice, $request);
                }
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->withDetails($invoice->fresh()), 200);
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

        $this->deleteInvoiceImage($invoice);
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

        return response($this->withDetails($invoice), 200);
    }

    /**
     * آپلود یا جایگزینی عکس فاکتور
     * POST /api/invoices/{invoice}/image
     */
    public function uploadImage(Request $request, Invoice $invoice)
    {
        $this->assertModelBelongsToStaffAtelier($request, $invoice);

        $request->validate([
            'image' => 'nullable',
            'image_base64' => 'nullable|string',
        ]);

        if (! $this->requestHasImage($request)) {
            return response()->json(['message' => 'فایل عکس را ارسال کنید.'], 422);
        }

        try {
            $this->saveInvoiceImageFromRequest($invoice, $request);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->withDetails($invoice->fresh()), 200);
    }

    /**
     * حذف عکس فاکتور
     * DELETE /api/invoices/{invoice}/image
     */
    public function deleteImage(Request $request, Invoice $invoice)
    {
        $this->assertModelBelongsToStaffAtelier($request, $invoice);

        $this->deleteInvoiceImage($invoice);
        if (Schema::hasColumn('invoices', 'image_path')) {
            $invoice->update(['image_path' => null]);
        }

        return response($this->withDetails($invoice->fresh()), 200);
    }

    protected function withDetails(Invoice $invoice): Invoice
    {
        $relations = [];
        if ($this->supportsInvoiceItems()) {
            $relations[] = 'items';
        }
        if ($this->supportsPaymentAccount('invoices')) {
            $relations[] = 'shopAccount';
            $relations[] = 'cheque';
        }
        if (ShopBeneficiaryService::supports('invoices')) {
            $relations[] = 'beneficiary';
        }
        if ($relations !== []) {
            $invoice->loadMissing($relations);
        }

        return ShopBeneficiaryService::attachTo($this->attachPaymentAccount($invoice));
    }

    protected function withAccount(Invoice $invoice): Invoice
    {
        return $this->withDetails($invoice);
    }

    protected function supportsInvoiceItems(): bool
    {
        return Schema::hasTable('invoice_items');
    }

    protected function prepareInvoiceRequest(Request $request): void
    {
        $this->mergeRequestPayload($request, [
            'amount',
            'title',
            'description',
            'date',
            'items',
            'image',
            'image_base64',
            'remove_image',
            'shop_account_id',
            'payment_method',
            'cheque',
            'cheque_id',
            'beneficiary_id',
            'user_shiksho_id',
        ]);

        $items = $request->input('items');
        if (is_string($items) && $items !== '') {
            $decoded = json_decode($items, true);
            if (is_array($decoded)) {
                $request->merge(['items' => $decoded]);
            }
        }
    }

    protected function itemsWereSent(Request $request): bool
    {
        return $request->exists('items');
    }

    /**
     * @return array<int, array{title: string, unit_price: float, quantity: float, total: float, sort_order: int}>|null
     */
    protected function normalizedItemsFromRequest(Request $request): ?array
    {
        if (! $request->exists('items') && ! $request->has('items')) {
            return [];
        }

        $raw = $request->input('items');
        if ($raw === null || $raw === '') {
            return [];
        }
        if (! is_array($raw)) {
            return null;
        }

        $rows = [];
        foreach (array_values($raw) as $index => $item) {
            if (! is_array($item)) {
                return null;
            }

            $title = trim((string) ($item['title'] ?? $item['name'] ?? $item['عنوان'] ?? ''));
            if ($title === '') {
                return null;
            }

            $unitPrice = $item['unit_price'] ?? $item['price'] ?? $item['unitPrice'] ?? $item['فی'] ?? null;
            $quantity = $item['quantity'] ?? $item['qty'] ?? $item['count'] ?? $item['تعداد'] ?? null;
            if ($unitPrice === null || $quantity === null) {
                return null;
            }

            $unitPrice = (float) $unitPrice;
            $quantity = (float) $quantity;
            if ($unitPrice < 0 || $quantity <= 0) {
                return null;
            }

            $rows[] = [
                'title' => mb_substr($title, 0, 255),
                'unit_price' => round($unitPrice, 2),
                'quantity' => round($quantity, 3),
                'total' => round($unitPrice * $quantity, 2),
                'sort_order' => $index,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{total: float}>  $rows
     */
    protected function sumItemTotals(array $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float) $row['total'];
        }

        return round($sum, 2);
    }

    protected function requestSentAmount(Request $request): bool
    {
        if (! $request->exists('amount')) {
            return false;
        }

        $value = $request->input('amount');

        return $value !== null && $value !== '';
    }

    /**
     * فاکتور دارای آیتم نباید مبلغ کلی جدا از مجموع آیتم‌ها داشته باشد.
     *
     * @param  array<int, array{total: float}>  $itemRows
     */
    protected function amountConflictWithItems(Request $request, array $itemRows): ?string
    {
        if ($itemRows === [] || ! $this->requestSentAmount($request)) {
            return null;
        }

        $itemsSum = $this->sumItemTotals($itemRows);
        if (abs(round((float) $request->input('amount'), 2) - $itemsSum) <= 0.001) {
            return null;
        }

        return $this->amountWithItemsMessage($itemsSum, $request->input('amount'));
    }

    protected function amountWithItemsMessage($itemsSum, $sentAmount): string
    {
        return 'این فاکتور آیتم دارد و مبلغ کلی جداگانه قبول نیست. مبلغ فاکتور برابر مجموع آیتم‌هاست ('
            .number_format((float) $itemsSum)
            .'). مبلغ ارسال‌شده ('
            .number_format((float) $sentAmount)
            .') را حذف کنید یا آیتم‌ها را اصلاح کنید.';
    }

    /**
     * @param  array<int, array{title: string, unit_price: float, quantity: float, total: float, sort_order: int}>  $rows
     */
    protected function syncInvoiceItems(Invoice $invoice, array $rows): void
    {
        if (! $this->supportsInvoiceItems()) {
            return;
        }

        $invoice->items()->delete();
        foreach ($rows as $row) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'title' => $row['title'],
                'unit_price' => $row['unit_price'],
                'quantity' => $row['quantity'],
                'total' => $row['total'],
                'sort_order' => $row['sort_order'],
            ]);
        }
    }

    protected function requestHasImage(Request $request): bool
    {
        if ($request->hasFile('image')) {
            return true;
        }

        $value = $request->input('image_base64', $request->input('image'));

        return is_string($value) && $value !== '' && ! $request->hasFile('image');
    }

    protected function saveInvoiceImageFromRequest(Invoice $invoice, Request $request): void
    {
        if (! Schema::hasColumn('invoices', 'image_path') || ! $this->requestHasImage($request)) {
            return;
        }

        $ext = 'jpeg';
        $content = null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $origExt = strtolower((string) $file->getClientOriginalExtension());
            $ext = in_array($origExt, ['jpg', 'jpeg', 'png', 'webp'], true) ? $origExt : 'jpeg';
            if (! in_array(strtolower((string) $file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)
                && ! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
                throw new RuntimeException('فرمت عکس باید jpg، png یا webp باشد.');
            }
            $content = file_get_contents($file->getRealPath());
        } else {
            $raw = (string) $request->input('image_base64', $request->input('image'));
            if (strpos($raw, ',') !== false) {
                $header = strtolower(strstr($raw, ',', true) ?: '');
                $raw = substr($raw, strpos($raw, ',') + 1);
                if (strpos($header, 'png') !== false) {
                    $ext = 'png';
                } elseif (strpos($header, 'webp') !== false) {
                    $ext = 'webp';
                }
            }
            $content = base64_decode($raw);
        }

        if ($content === false || $content === null || $content === '') {
            throw new RuntimeException('عکس فاکتور نامعتبر است.');
        }
        if (strlen($content) > 5 * 1024 * 1024) {
            throw new RuntimeException('حجم عکس نباید بیشتر از ۵ مگابایت باشد.');
        }

        $this->deleteInvoiceImage($invoice);

        $path = ImageTools::saveFile(
            "/invoices/{$invoice->id}/image_".time().".{$ext}",
            $content
        );
        $invoice->update(['image_path' => $path]);
    }

    protected function deleteInvoiceImage(Invoice $invoice): void
    {
        $path = $invoice->getOriginal('image_path') ?: ($invoice->attributes['image_path'] ?? $invoice->image_path ?? null);
        if ($path && Storage::exists('public/'.$path)) {
            Storage::delete('public/'.$path);
        }
    }
}
