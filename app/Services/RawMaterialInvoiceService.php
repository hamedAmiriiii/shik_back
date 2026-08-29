<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\RawMaterial;
use App\Models\RawMaterialLot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
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
        ], DocumentPaymentService::requestRules(), ShopBeneficiaryService::requestRules('invoices'));
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

        return ! empty($fields['quantity_kg'])
            && ! empty($fields['shop_account_id'])
            && (($fields['payment_method'] ?? 'account') === 'account');
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

        return $lot->fresh(['invoice', 'invoiceItem']);
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
        $payload = [
            'amount' => $amount,
            'title' => $title,
            'description' => $fields['note'] ?? null,
            'date' => Carbon::now()->format('Y-m-d'),
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

        $payment = DocumentPaymentService::resolveOnCreate($atelierId, $fields, $amount, 'invoices');
        $chequePayload = [
            'cheque' => $fields['cheque'] ?? null,
            'cheque_id' => $fields['cheque_id'] ?? null,
            'payment_method' => $payment['payment_method'] ?? ($fields['payment_method'] ?? null),
        ];
        $payload = array_merge($payload, $payment);

        $invoice = Invoice::create($payload);
        DocumentPaymentService::attachChequeFromRequest($invoice, $chequePayload, $userName);

        return $invoice;
    }
}
