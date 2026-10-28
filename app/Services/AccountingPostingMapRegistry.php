<?php

namespace App\Services;

/**
 * فهرست ثابت نقش‌های حسابداری نرم‌افزار → کد پیش‌فرض درخت.
 * حسابدار می‌تواند برای هر فروشگاه account_id را در accounting_posting_maps عوض کند.
 */
class AccountingPostingMapRegistry
{
    /**
     * @return array<int, array{key:string,group:string,group_label:string,label:string,default_code:string,description:string}>
     */
    public static function all(): array
    {
        $c = ChartOfAccountsSeeder::class;

        return [
            // فروش
            self::row('purchase.revenue', 'sale', 'فروش', 'درآمد فروش', $c::CODE_REVENUE, 'بستانکار فروش کالا'),
            self::row('purchase.discount', 'sale', 'فروش', 'تخفیف فروش', $c::CODE_DISCOUNT, 'تخفیف روی فاکتور فروش'),
            self::row('purchase.loyalty', 'sale', 'فروش', 'اعتبار / تخفیف باشگاه', $c::CODE_LOYALTY, 'مصرف اعتبار وفاداری هنگام فروش'),
            self::row('purchase.cash', 'sale', 'فروش', 'دریافت نقد فروش', $c::CODE_TILL, 'وقتی فروش نقد از صندوق است'),
            self::row('purchase.card', 'sale', 'فروش', 'کارتخوان فروش', $c::CODE_POS, 'وجه در راه کارتخوان'),
            self::row('purchase.cheque_receivable', 'sale', 'فروش', 'چک دریافتی فروش', $c::CODE_CHEQUE_RECEIVABLE, 'فروش چکی'),
            self::row('purchase.ar', 'sale', 'فروش', 'طلب از مشتری (نسیه فروش)', $c::CODE_AR, 'فروش نسیه / قسط'),
            self::row('purchase.cogs', 'sale', 'فروش', 'بهای تمام‌شده فروش', $c::CODE_COGS, 'بدهکار بهای تمام‌شده'),
            self::row('purchase.cogs_inv_catalog', 'sale', 'فروش', 'خروج موجودی کاتالوگ', $c::CODE_INV_CATALOG, 'بستانکار موجودی کالای فروش‌رفته'),
            self::row('purchase.cogs_inv_raw', 'sale', 'فروش', 'خروج موجودی مواد', $c::CODE_INV_RAW, 'وقتی قلم فروش از مواد اولیه است'),
            self::row('purchase.cogs_inv_finished', 'sale', 'فروش', 'خروج کالای ساخته‌شده', $c::CODE_INV_FINISHED, 'وقتی قلم فروش کالای تولیدی است'),

            // وصول و اقساط
            self::row('installment_pay.cash', 'collections', 'وصول و اقساط', 'نقد اقساط', $c::CODE_TILL, 'پرداخت قسط نقدی'),
            self::row('installment_pay.ar', 'collections', 'وصول و اقساط', 'بستن طلب قسط', $c::CODE_AR, 'کاهش طلب مشتری با قسط'),
            self::row('debt_settle.cash', 'collections', 'وصول و اقساط', 'نقد تسویه نسیه', $c::CODE_TILL, 'تسویه بدهی مشتری نقد'),
            self::row('debt_settle.card', 'collections', 'وصول و اقساط', 'کارت تسویه نسیه', $c::CODE_POS, 'تسویه بدهی با کارتخوان'),
            self::row('debt_settle.ar', 'collections', 'وصول و اقساط', 'بستن طلب نسیه', $c::CODE_AR, 'کاهش طلب با تسویه'),
            self::row('debt_payment.cash', 'collections', 'وصول و اقساط', 'نقد پرداخت بدهی', $c::CODE_TILL, 'پرداخت جزئی بدهی'),
            self::row('debt_payment.card', 'collections', 'وصول و اقساط', 'کارت پرداخت بدهی', $c::CODE_POS, ''),
            self::row('debt_payment.ar', 'collections', 'وصول و اقساط', 'بستن طلب پرداخت بدهی', $c::CODE_AR, ''),

            // فاکتور خرید
            self::row('invoice.inv_raw', 'purchase', 'فاکتور خرید', 'مواد اولیه خرید', $c::CODE_INV_RAW, 'سهم لات‌های مواد در فاکتور'),
            self::row('invoice.inv_catalog', 'purchase', 'فاکتور خرید', 'کالای خریداری‌شده', $c::CODE_INV_CATALOG, 'باقی مبلغ فاکتور به موجودی کاتالوگ'),
            self::row('invoice.ap', 'purchase', 'فاکتور خرید', 'بدهی به تأمین‌کننده', $c::CODE_AP, 'نسیه / مانده فاکتور خرید'),
            self::row('invoice.cheque_payable', 'purchase', 'فاکتور خرید', 'چک صادره خرید', $c::CODE_CHEQUE_PAYABLE, 'پرداخت فاکتور با چک'),
            self::row('invoice_vat.vat_input', 'purchase', 'فاکتور خرید', 'ارزش افزوده خرید (فاکتور)', $c::CODE_VAT_INPUT, 'سند جدا VAT فاکتور'),
            self::row('invoice_vat.credit_inventory', 'purchase', 'فاکتور خرید', 'جداسازی VAT از موجودی', $c::CODE_INV_CATALOG, 'بستانکار موجودی در سند VAT'),
            self::row('document_payment.ap', 'purchase', 'فاکتور خرید', 'تسویه نسیه تأمین‌کننده', $c::CODE_AP, 'پرداخت بعدی بدهی فاکتور/هزینه'),

            // هزینه
            self::row('expense.debit_opex', 'expense', 'هزینه', 'هزینه جاری پیش‌فرض', $c::CODE_EXPENSE, 'اگر حساب درخت روی هزینه انتخاب نشده باشد'),
            self::row('expense.debit_payroll', 'expense', 'هزینه', 'هزینه حقوق', $c::CODE_PAYROLL, 'هزینه‌های حقوق و دستمزد'),
            self::row('expense.debit_capex', 'expense', 'هزینه', 'دارایی سرمایه‌ای', $c::CODE_CAPEX, 'هزینه نوع سرمایه'),
            self::row('expense.ap', 'expense', 'هزینه', 'بدهی هزینه نسیه', $c::CODE_AP, ''),
            self::row('expense.cheque_payable', 'expense', 'هزینه', 'چک صادره هزینه', $c::CODE_CHEQUE_PAYABLE, ''),
            self::row('expense_vat.vat_input', 'expense', 'هزینه', 'ارزش افزوده خرید (هزینه)', $c::CODE_VAT_INPUT, ''),
            self::row('expense.manual_credit_grant.loyalty', 'expense', 'هزینه', 'اعطای اعتبار باشگاه', $c::CODE_LOYALTY, 'اعتبار دستی به مشتری'),
            self::row('expense.manual_credit_grant.ar', 'expense', 'هزینه', 'بدهی اعتبار اعطایی', $c::CODE_AR, 'طرف مقابل اعطای اعتبار'),

            // برگشت فروش
            self::row('purchase_return.discount', 'return', 'برگشت فروش', 'تخفیف برگشت', $c::CODE_DISCOUNT, ''),
            self::row('purchase_return.loyalty', 'return', 'برگشت فروش', 'برگرداندن اعتبار باشگاه', $c::CODE_LOYALTY, ''),
            self::row('purchase_return.cash', 'return', 'برگشت فروش', 'نقد برگشتی', $c::CODE_TILL, ''),
            self::row('purchase_return.pos', 'return', 'برگشت فروش', 'کارتخوان برگشتی', $c::CODE_POS, ''),
            self::row('purchase_return.ar', 'return', 'برگشت فروش', 'کاهش طلب برگشت', $c::CODE_AR, ''),
            self::row('purchase_return.cheque_receivable', 'return', 'برگشت فروش', 'چک برگشتی', $c::CODE_CHEQUE_RECEIVABLE, ''),
            self::row('purchase_return.inv_catalog', 'return', 'برگشت فروش', 'برگشت به موجودی کاتالوگ', $c::CODE_INV_CATALOG, ''),
            self::row('purchase_return.inv_raw', 'return', 'برگشت فروش', 'برگشت به مواد اولیه', $c::CODE_INV_RAW, ''),
            self::row('purchase_return.inv_finished', 'return', 'برگشت فروش', 'برگشت به کالای ساخته', $c::CODE_INV_FINISHED, ''),
            self::row('purchase_return.cogs', 'return', 'برگشت فروش', 'برگشت بهای تمام‌شده', $c::CODE_COGS, ''),

            // تولید و ضایعات
            self::row('production.inv_finished', 'production', 'تولید و ضایعات', 'کالای ساخته‌شده تولید', $c::CODE_INV_FINISHED, ''),
            self::row('production.inv_raw', 'production', 'تولید و ضایعات', 'مصرف مواد در تولید', $c::CODE_INV_RAW, ''),
            self::row('inventory_waste.cogs', 'production', 'تولید و ضایعات', 'ضایعات (بهای تمام‌شده)', $c::CODE_COGS, ''),
            self::row('inventory_waste.inv_finished', 'production', 'تولید و ضایعات', 'ضایعات کالای ساخته', $c::CODE_INV_FINISHED, ''),
            self::row('inventory_waste.inv_raw', 'production', 'تولید و ضایعات', 'ضایعات مواد', $c::CODE_INV_RAW, ''),

            // چک و پیش‌پرداخت
            self::row('income.cheque_receivable', 'cheque', 'چک و پیش‌پرداخت', 'چک دریافتی متفرقه', $c::CODE_CHEQUE_RECEIVABLE, ''),
            self::row('income.other_income', 'cheque', 'چک و پیش‌پرداخت', 'درآمد متفرقه چک', $c::CODE_OTHER_INCOME, ''),
            self::row('cheque_clear_issued.payable', 'cheque', 'چک و پیش‌پرداخت', 'وصول چک صادره', $c::CODE_CHEQUE_PAYABLE, ''),
            self::row('cheque_clear_received.cheque_receivable', 'cheque', 'چک و پیش‌پرداخت', 'وصول چک دریافتی', $c::CODE_CHEQUE_RECEIVABLE, ''),
            self::row('cheque_clear_received.cash_fallback', 'cheque', 'چک و پیش‌پرداخت', 'صندوق تا تطبیق (وصول چک)', $c::CODE_TILL, 'بعد از وصول چک دریافتی؛ واریز به بانک با تطبیق'),
            self::row('cheque_transfer.ap', 'cheque', 'چک و پیش‌پرداخت', 'ظهرنویسی به بدهی', $c::CODE_AP, ''),
            self::row('cheque_transfer.supplier_advance', 'cheque', 'چک و پیش‌پرداخت', 'ظهرنویسی مازاد (پیش‌پرداخت)', $c::CODE_SUPPLIER_ADVANCE, ''),
            self::row('cheque_transfer.cheque_receivable', 'cheque', 'چک و پیش‌پرداخت', 'خروج چک ظهرنویسی', $c::CODE_CHEQUE_RECEIVABLE, ''),
            self::row('beneficiary_advance.ap', 'cheque', 'چک و پیش‌پرداخت', 'مصرف پیش‌پرداخت تأمین‌کننده', $c::CODE_AP, ''),
            self::row('beneficiary_advance.supplier_advance', 'cheque', 'چک و پیش‌پرداخت', 'پیش‌پرداخت تأمین‌کننده', $c::CODE_SUPPLIER_ADVANCE, ''),

            // متفرقه و سرمایه
            self::row('manual_trade.purchase_expense', 'misc', 'متفرقه و سرمایه', 'سند دستی خرید/هزینه', $c::CODE_EXPENSE, ''),
            self::row('manual_trade.sale_income', 'misc', 'متفرقه و سرمایه', 'سند دستی درآمد', $c::CODE_OTHER_INCOME, ''),
            self::row('recon_deposit.pos', 'misc', 'متفرقه و سرمایه', 'کارتخوان تطبیق روزانه', $c::CODE_POS, ''),
            self::row('recon_deposit.till', 'misc', 'متفرقه و سرمایه', 'صندوق تطبیق روزانه', $c::CODE_TILL, ''),
            self::row('opening.equity', 'misc', 'متفرقه و سرمایه', 'افتتاحیه (سرمایه)', $c::CODE_EQUITY, ''),
            self::row('balance_adjust.equity', 'misc', 'متفرقه و سرمایه', 'تعدیل موجودی حساب (سرمایه)', $c::CODE_EQUITY, ''),
            self::row('partner_settlement.equity', 'misc', 'متفرقه و سرمایه', 'تسویه شریک (سرمایه)', $c::CODE_EQUITY, ''),
            self::row('year_close.retained', 'misc', 'متفرقه و سرمایه', 'سود انباشته بستن سال', $c::CODE_RETAINED, ''),
        ];
    }

    /**
     * @return array{key:string,group:string,group_label:string,label:string,default_code:string,description:string}|null
     */
    public static function find(string $eventKey): ?array
    {
        foreach (self::all() as $row) {
            if ($row['key'] === $eventKey) {
                return $row;
            }
        }

        return null;
    }

    public static function defaultCode(string $eventKey): ?string
    {
        $row = self::find($eventKey);

        return $row['default_code'] ?? null;
    }

    /**
     * @return array{key:string,group:string,group_label:string,label:string,default_code:string,description:string}
     */
    private static function row(
        string $key,
        string $group,
        string $groupLabel,
        string $label,
        string $defaultCode,
        string $description
    ): array {
        return [
            'key' => $key,
            'group' => $group,
            'group_label' => $groupLabel,
            'label' => $label,
            'default_code' => $defaultCode,
            'description' => $description,
        ];
    }
}
