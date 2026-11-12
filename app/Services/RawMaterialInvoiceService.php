<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\RawMaterial;
use App\Models\RawMaterialLot;
use App\Tools\ProductQuantityTools;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;
use RuntimeException;

class RawMaterialInvoiceService
{
    /**
     * @return array<string, string>
     */
    public static function requestRules(): array
    {
        return array_merge([
            'create_invoice' => 'nullable|boolean',
            'invoice_id' => 'nullable|integer',
            'invoice_link' => 'nullable|string|in:whole,item,invoice,line,all',
            'invoice_item_id' => 'nullable|integer',
            'invoice_title' => 'nullable|string|max:255',
            'date' => 'nullable',
            'invoice_date' => 'nullable',
        ], PurchaseVatService::requestRules(), DocumentPaymentService::requestRules(), ShopBeneficiaryService::requestRules('invoices'));
    }

    public function wantsInvoice(array $fields): bool
    {
        if (! empty($fields['invoice_id'])) {
            return true;
        }

        $flag = $fields['create_invoice'] ?? false;
        if (filter_var($flag, FILTER_VALIDATE_BOOLEAN) || $flag === 1 || $flag === '1') {
            return true;
        }

        if (empty($fields['quantity_kg'])) {
            return false;
        }

        foreach (['payments', 'cash_amount', 'cheque_amount', 'credit_amount', 'cheque', 'cheque_id', 'shop_account_id', 'payment_method'] as $key) {
            if (! empty($fields[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * چند لات ماده اولیه را با یک فاکتور جمعی ثبت می‌کند (آیتم‌ها + VAT + پرداخت).
     *
     * @param  list<array{material: RawMaterial, lot: RawMaterialLot}>  $rows
     * @param  array<string, mixed>  $fields
     */
    public function attachBulk(array $rows, array $fields, $actor): Invoice
    {
        if ($rows === []) {
            throw new RuntimeException('حداقل یک ماده اولیه برای فاکتور جمعی لازم است.');
        }
        if (! Schema::hasColumn('raw_material_lots', 'invoice_id')) {
            throw new RuntimeException('اتصال فاکتور به مواد اولیه آماده نیست. SQL مربوط را اجرا کنید.');
        }

        $prepared = [];
        $totalAmount = 0.0;
        foreach ($rows as $row) {
            /** @var RawMaterial $material */
            $material = $row['material'];
            /** @var RawMaterialLot $lot */
            $lot = $row['lot'];
            $qty = round((float) $lot->quantity_kg, 3);
            $price = round((float) $lot->price_per_kg, 2);
            $amount = round($qty * $price, 2);
            if ($qty < 0.001 || $amount < 0) {
                throw new RuntimeException('مقدار یا مبلغ یکی از ردیف‌های فاکتور جمعی نامعتبر است.');
            }
            $prepared[] = compact('material', 'lot', 'qty', 'price', 'amount');
            $totalAmount = round($totalAmount + $amount, 2);
        }

        if ($totalAmount < 0.01) {
            throw new RuntimeException('جمع مبلغ فاکتور جمعی باید بیشتر از صفر باشد.');
        }

        if (empty($fields['invoice_title']) && empty($fields['title'])) {
            $names = array_values(array_unique(array_map(
                static fn (array $row) => (string) $row['material']->name,
                $prepared
            )));
            $fields['invoice_title'] = count($names) === 1
                ? 'خرید ماده اولیه: '.$names[0]
                : 'خرید مواد اولیه ('.count($names).' قلم)';
        }

        $invoice = $this->createInvoice($prepared[0]['material'], $fields, $actor, $totalAmount);

        $sort = 0;
        foreach ($prepared as $row) {
            $item = null;
            if (Schema::hasTable('invoice_items')) {
                $item = InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'title' => $row['material']->name,
                    'unit_price' => $row['price'],
                    'quantity' => $row['qty'],
                    'total' => $row['amount'],
                    'sort_order' => $sort,
                ]);
                $sort++;
            }

            $row['lot']->update([
                'invoice_id' => $invoice->id,
                'invoice_item_id' => $item?->id,
            ]);
        }

        $invoice = $invoice->fresh(['payments', 'rawMaterialLots', 'items']);
        foreach ($prepared as $row) {
            $this->absorbInvoiceVatIntoLotPrice($row['lot']->fresh(), $invoice);
        }

        AccountingDocumentPoster::syncInvoice($invoice->fresh(['payments', 'rawMaterialLots']));

        return $invoice->fresh(['payments', 'shopAccount', 'cheque', 'items', 'rawMaterialLots']);
    }

    /**
     * وصل کردن خرید ماده به فاکتور موجود، یا صدور فاکتور همزمان.
     *
     * invoice_link=whole → کل فاکتور
     * invoice_link=item  → یک آیتم فاکتور (موجود یا جدید)
     */
    public function attach(RawMaterial $material, RawMaterialLot $lot, array $fields, $actor): RawMaterialLot
    {
        if (! $this->wantsInvoice($fields)) {
            return $lot;
        }

        if (! Schema::hasColumn('raw_material_lots', 'invoice_id')) {
            throw new RuntimeException('اتصال فاکتور به مواد اولیه آماده نیست. SQL مربوط را اجرا کنید.');
        }

        $qty = (float) $lot->quantity_kg;
        $price = (float) $lot->price_per_kg;
        $amount = round($qty * $price, 2);
        $invoiceId = (int) ($fields['invoice_id'] ?? 0);
        $link = $this->normalizeLinkType($fields, $invoiceId);

        if ($invoiceId > 0) {
            $invoice = Invoice::query()
                ->where('id', $invoiceId)
                ->where('atelier_id', (int) $material->atelier_id)
                ->first();
            if (! $invoice) {
                throw new RuntimeException('فاکتور انتخاب‌شده متعلق به این فروشگاه نیست.');
            }

            $itemId = null;
            if ($link === 'item') {
                $item = $this->resolveOrCreateItem($invoice, $fields, $material, $qty, $price, $amount);
                $itemId = $item->id;
            }

            $lot->update([
                'invoice_id' => $invoice->id,
                'invoice_item_id' => $itemId,
            ]);

            AccountingDocumentPoster::syncInvoice($invoice->fresh(['payments', 'rawMaterialLots']));

            return $lot->fresh(['invoice', 'invoiceItem']);
        }

        $invoice = $this->createInvoice($material, $fields, $actor, $amount);
        $item = null;
        if (Schema::hasTable('invoice_items')) {
            $item = InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'title' => $material->name,
                'unit_price' => $price,
                'quantity' => $qty,
                'total' => $amount,
                'sort_order' => 0,
            ]);
        }

        $lot->update([
            'invoice_id' => $invoice->id,
            'invoice_item_id' => $item?->id,
        ]);

        // فاکتور: مبلغ پایه + VAT جدا؛ لات/بهای تمام‌شده: واحد شامل VAT
        $this->absorbInvoiceVatIntoLotPrice($lot->fresh(), $invoice);

        AccountingDocumentPoster::syncInvoice($invoice->fresh(['payments', 'rawMaterialLots']));

        return $lot->fresh(['invoice', 'invoiceItem']);
    }

    /**
     * سهم ارزش افزوده فاکتور را روی قیمت واحد لات پخش می‌کند
     * (مثلاً ۱۰۰ + ۱۰٪ → قیمت لات ۱۱۰ برای بهای تمام‌شده/تولید).
     */
    protected function absorbInvoiceVatIntoLotPrice(RawMaterialLot $lot, Invoice $invoice): void
    {
        $vat = PurchaseVatService::vatAmount($invoice);
        $qty = round((float) $lot->quantity_kg, 3);
        if ($vat < 0.01 || $qty < 0.001) {
            return;
        }

        $baseUnit = round((float) $lot->price_per_kg, 2);
        $lotBase = round($qty * $baseUnit, 2);
        $invoiceBase = round((float) $invoice->amount, 2);
        if ($invoiceBase < 0.01 || $lotBase < 0.01) {
            return;
        }

        $vatShare = round($vat * ($lotBase / $invoiceBase), 2);
        $newUnit = round($baseUnit + ($vatShare / $qty), 2);
        if ($newUnit <= $baseUnit) {
            return;
        }

        $lot->update(['price_per_kg' => $newUnit]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function normalizeLinkType(array $fields, int $invoiceId): string
    {
        $raw = strtolower(trim((string) ($fields['invoice_link'] ?? '')));
        if (in_array($raw, ['item', 'line'], true)) {
            return 'item';
        }
        if (in_array($raw, ['whole', 'invoice', 'all'], true)) {
            return 'whole';
        }
        if (! empty($fields['invoice_item_id'])) {
            return 'item';
        }

        return $invoiceId > 0 ? 'whole' : 'item';
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function resolveOrCreateItem(
        Invoice $invoice,
        array $fields,
        RawMaterial $material,
        float $qty,
        float $price,
        float $amount
    ): InvoiceItem {
        if (! Schema::hasTable('invoice_items')) {
            throw new RuntimeException('جدول آیتم‌های فاکتور وجود ندارد. SQL آیتم فاکتور را اجرا کنید.');
        }

        if (! empty($fields['invoice_item_id'])) {
            $item = InvoiceItem::query()
                ->where('id', (int) $fields['invoice_item_id'])
                ->where('invoice_id', $invoice->id)
                ->first();
            if (! $item) {
                throw new RuntimeException('آیتم انتخاب‌شده متعلق به این فاکتور نیست.');
            }

            return $item;
        }

        $sort = (int) $invoice->items()->max('sort_order');
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'title' => $material->name,
            'unit_price' => $price,
            'quantity' => $qty,
            'total' => $amount,
            'sort_order' => $sort + 1,
        ]);

        $invoice->unsetRelation('items');
        $newAmount = $invoice->itemsSum();
        if (DocumentPaymentService::isPaid($invoice) && $invoice->shop_account_id) {
            DocumentPaymentService::assertCanDebit(
                (int) $invoice->atelier_id,
                (int) $invoice->shop_account_id,
                $newAmount,
                $invoice
            );
        }
        $invoice->update(['amount' => $newAmount]);
        PurchaseVatService::refreshForBaseChange($invoice->fresh());
        // ردیف نسیه را با مبلغ جدید فاکتور هم‌تراز کن (در غیر این صورت فقط amount عوض می‌شود)
        DocumentPaymentService::alignSplitsToDocumentAmount($invoice->fresh(['payments']));

        return $item;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function createInvoice(RawMaterial $material, array $fields, $actor, float $amount): Invoice
    {
        $atelierId = (int) $material->atelier_id;
        $userName = 'کاربر سیستم';
        if ($actor) {
            $userName = trim(($actor->name ?? '').' '.($actor->last_name ?? ''));
            if ($userName === '') {
                $userName = 'کاربر سیستم';
            }
        }

        $title = $fields['invoice_title'] ?? ('خرید ماده اولیه: '.$material->name);
        $vatNorm = PurchaseVatService::normalize($atelierId, $amount, $fields, 'invoices');
        $payable = round($amount + (float) $vatNorm['vat_amount'], 2);
        $payload = [
            'amount' => $amount,
            'vat_mode' => $vatNorm['vat_mode'],
            'vat_percent' => $vatNorm['vat_percent'],
            'vat_amount' => $vatNorm['vat_amount'],
            'title' => $title,
            'description' => $fields['note'] ?? ($fields['invoice_description'] ?? null),
            'date' => $this->resolveInvoiceDate($fields) ?: Carbon::now()->format('Y-m-d'),
            'user_name' => $userName,
            'atelier_id' => $atelierId,
        ];

        $beneficiaryFields = $fields;
        $beneficiaryError = ShopBeneficiaryService::applyToFields($atelierId, $beneficiaryFields, true, 'invoices');
        if ($beneficiaryError) {
            throw new RuntimeException($beneficiaryError);
        }
        if (array_key_exists('beneficiary_id', $beneficiaryFields)) {
            $payload['beneficiary_id'] = $beneficiaryFields['beneficiary_id'];
        }

        $paymentSource = $fields;
        $payment = DocumentPaymentService::resolveOnCreate($atelierId, $fields, $payable, 'invoices');
        $payload = array_merge($payload, $payment);

        $invoice = DB::transaction(function () use ($payload) {
            return Invoice::create($payload);
        });
        DocumentPaymentService::attachChequeFromRequest($invoice, $paymentSource, $userName);

        return $invoice->fresh(['payments', 'shopAccount', 'cheque']);
    }

    /**
     * اقلام فاکتور را به لات مواد اولیه تبدیل می‌کند. لات مصرف‌نشدهٔ همین فاکتور جایگزین می‌شود.
     */
    public function syncFromInvoice(Invoice $invoice): void
    {
        if (! Schema::hasTable('raw_materials') || ! Schema::hasTable('raw_material_lots')) {
            throw new RuntimeException('جداول مواد اولیه آماده نیست.');
        }
        if (! Schema::hasColumn('raw_material_lots', 'invoice_id')) {
            throw new RuntimeException('اتصال فاکتور به مواد اولیه آماده نیست. SQL مربوط را اجرا کنید.');
        }

        $invoice->loadMissing('items');
        $atelierId = (int) $invoice->atelier_id;

        $existingLots = RawMaterialLot::query()
            ->where('invoice_id', $invoice->id)
            ->lockForUpdate()
            ->get();
        foreach ($existingLots as $lot) {
            if (! $lot->isUntouched()) {
                throw new RuntimeException('برخی مواد اولیه این فاکتور قبلاً مصرف شده‌اند و قابل ویرایش نیستند.');
            }
        }
        foreach ($existingLots as $lot) {
            $lot->delete();
        }

        $purchasedAt = $this->purchasedAt($invoice);
        $prepared = [];
        foreach ($invoice->items as $item) {
            $name = $this->normalizeName($item->title);
            $qty = round((float) $item->quantity, 3);
            $unitPrice = round((float) $item->unit_price, 2);
            if ($name === '' || $qty <= 0 || $unitPrice < 0) {
                continue;
            }
            $unit = $this->unitOf($item);
            if ($unit === RawMaterial::UNIT_PIECE && ! ProductQuantityTools::isWholeNumber($qty)) {
                throw new RuntimeException('تعداد ماده اولیه «'.$name.'» باید عدد صحیح باشد.');
            }
            $prepared[] = [
                'item' => $item,
                'name' => $name,
                'qty' => $qty,
                'unit' => $unit,
                'gross' => round($qty * $unitPrice + (float) ($item->vat_amount ?? 0), 2),
                'line_discount' => round((float) ($item->discount_amount ?? 0), 2),
            ];
        }

        $lineDiscountSum = round(array_sum(array_column($prepared, 'line_discount')), 2);
        $headerDiscount = $lineDiscountSum > 0
            ? 0.0
            : (Schema::hasColumn('invoices', 'discount_amount') ? round((float) ($invoice->discount_amount ?? 0), 2) : 0.0);
        $shares = $lineDiscountSum > 0
            ? array_column($prepared, 'line_discount')
            : $this->allocate($prepared, $headerDiscount);

        foreach ($prepared as $index => $row) {
            $material = RawMaterial::query()
                ->where('atelier_id', $atelierId)
                ->where('name', $row['name'])
                ->first();
            if (! $material) {
                $create = [
                    'atelier_id' => $atelierId,
                    'name' => $row['name'],
                    'sale_price' => 0,
                ];
                if (Schema::hasColumn('raw_materials', 'unit_type')) {
                    $create['unit_type'] = $row['unit'];
                }
                $material = RawMaterial::create($create);
            } elseif ($material->unitType() !== $row['unit']) {
                throw new RuntimeException(
                    '«'.$row['name'].'» در مواد اولیه با واحد '.$material->unitLabelFa().' ثبت شده است.'
                );
            }

            $net = max(0, round($row['gross'] - (float) ($shares[$index] ?? 0), 2));
            $unitCost = $row['qty'] > 0 ? round($net / $row['qty'], 2) : 0.0;
            RawMaterialLot::create([
                'atelier_id' => $atelierId,
                'raw_material_id' => $material->id,
                'quantity_kg' => $row['qty'],
                'remaining_kg' => $row['qty'],
                'price_per_kg' => $unitCost,
                'purchased_at' => $purchasedAt,
                'invoice_id' => $invoice->id,
                'invoice_item_id' => $row['item']->id,
            ]);
        }
    }

    protected function normalizeName(?string $name): string
    {
        $name = trim((string) $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';

        return $name;
    }

    protected function unitOf(InvoiceItem $item): string
    {
        $value = Schema::hasColumn('invoice_items', 'unit_type') ? (string) ($item->unit_type ?? '') : '';
        if ($value === RawMaterial::UNIT_LITER || $value === RawMaterial::UNIT_PIECE) {
            return $value;
        }

        return RawMaterial::UNIT_KG;
    }

    /**
     * @param  list<array{gross: float}>  $rows
     * @return list<float>
     */
    protected function allocate(array $rows, float $discount): array
    {
        $shares = array_fill(0, count($rows), 0.0);
        if ($discount <= 0 || $rows === []) {
            return $shares;
        }
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float) $row['gross'];
        }
        if ($sum <= 0) {
            return $shares;
        }
        $allocated = 0.0;
        $last = count($rows) - 1;
        foreach ($rows as $index => $row) {
            if ($index === $last) {
                $shares[$index] = round($discount - $allocated, 2);
                break;
            }
            $share = round($discount * ((float) $row['gross'] / $sum), 2);
            $shares[$index] = $share;
            $allocated = round($allocated + $share, 2);
        }

        return $shares;
    }

    protected function purchasedAt(Invoice $invoice): Carbon
    {
        $raw = $invoice->getRawOriginal('date');
        if (! $raw) {
            $raw = $invoice->getAttributes()['date'] ?? null;
        }
        if ($raw instanceof Carbon) {
            $raw = $raw->format('Y-m-d');
        }
        if (is_string($raw) && trim($raw) !== '') {
            try {
                $parsed = Carbon::parse($raw);
                if ($parsed->year < 1700) {
                    return (new Jalalian($parsed->year, $parsed->month, $parsed->day))->toCarbon()->startOfDay();
                }

                return $parsed->startOfDay();
            } catch (\Throwable $e) {
            }
        }

        return now();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function resolveInvoiceDate(array $fields): ?string
    {
        $fromParts = DocumentPaymentService::parseJalaliDate($fields['invoice_date'] ?? $fields['date'] ?? null);
        if ($fromParts) {
            return $fromParts;
        }

        $raw = $fields['date'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $raw = str_replace(['\\', '-'], '/', trim($raw));
        if (! preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $raw, $m)) {
            return null;
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if ($year > 1600) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        try {
            return (new \Morilog\Jalali\Jalalian($year, $month, $day))->toCarbon()->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
