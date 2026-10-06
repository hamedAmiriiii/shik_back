<?php

namespace App\Services\GoogleSheets;

use App\Services\ShopBackupTables;
use Illuminate\Support\Facades\Schema;

/**
 * جداول قابل ارسال به گوگل شیت با عنوان فارسی و دسته‌بندی برای انتخاب فروشگاه.
 */
class GoogleSheetTableCatalog
{
    /** پیش‌فرض: خرید، مشتریان، اعتبارات، چک‌ها و نسیه‌ها */
    public const DEFAULT_TABLES = [
        'purchases',
        'purchased_products',
        'user_shiksho',
        'customers',
        'user_credit_grants',
        'cheques',
        'purchase_debt_payments',
    ];

    /** @var array<string, array<string, string>> دسته ← (جدول ← عنوان) */
    private const GROUPS = [
        'فروش' => [
            'purchases' => 'فاکتورهای فروش',
            'purchased_products' => 'اقلام فاکتورهای فروش',
            'purchase_item_returns' => 'برگشت اقلام فروش',
            'returned_products' => 'کالاهای برگشتی',
            'purchase_stock_consumptions' => 'مصرف موجودی فروش',
            'shop_daily_ticket_counters' => 'شمارندهٔ نوبت روزانه',
            'carts' => 'سبدهای خرید',
            'cart_items' => 'اقلام سبد خرید',
            'table_orders' => 'سفارش‌های میز',
            'table_order_items' => 'اقلام سفارش میز',
        ],
        'مشتریان و اعتبار' => [
            'user_shiksho' => 'مشتریان فروشگاه (اعتبار)',
            'customers' => 'مشتریان فروشگاه اینترنتی',
            'customer_addresses' => 'آدرس مشتریان',
            'user_credit_grants' => 'اعتبارات اعطاشده',
            'shop_loyalty_credit_tiers' => 'پله‌های اعتبار باشگاه',
            'shop_customer_groups' => 'گروه‌های مشتری',
            'shop_customer_group_members' => 'اعضای گروه‌های مشتری',
            'product_stock_notify_requests' => 'درخواست اطلاع از موجودی',
        ],
        'نسیه، چک و اقساط' => [
            'purchase_debt_payments' => 'پرداخت‌های نسیه',
            'cheques' => 'چک‌ها',
            'installments' => 'اقساط',
            'document_payments' => 'پرداخت اسناد',
        ],
        'کالا و انبار' => [
            'products' => 'کالاها',
            'product_images' => 'تصاویر کالا',
            'categories' => 'دسته‌بندی‌ها',
            'category_product' => 'دسته‌بندی کالاها',
            'manufacturers' => 'تولیدکنندگان',
            'raw_materials' => 'مواد اولیه',
            'raw_material_lots' => 'محموله‌های مواد اولیه',
            'produced_goods' => 'کالاهای تولیدی',
            'category_produced_good' => 'دسته‌بندی کالاهای تولیدی',
            'produced_good_ingredients' => 'اجزای کالاهای تولیدی',
            'productions' => 'تولیدها',
            'production_consumptions' => 'مصرف مواد در تولید',
            'oil_products' => 'کالاهای روغن',
        ],
        'مالی و حسابداری' => [
            'invoices' => 'فاکتورهای خرید',
            'invoice_items' => 'اقلام فاکتورهای خرید',
            'expenses' => 'هزینه‌ها',
            'incomes' => 'درآمدها',
            'shop_accounts' => 'حساب‌های فروشگاه',
            'shop_account_transfers' => 'انتقال بین حساب‌ها',
            'shop_account_balance_adjustments' => 'اصلاح موجودی حساب‌ها',
            'daily_shop_reconciliations' => 'تطبیق روزانه',
            'daily_shop_reconciliation_deposits' => 'واریزهای تطبیق روزانه',
            'daily_shop_reconciliation_account_deposits' => 'واریز حساب‌ها در تطبیق',
            'manual_trades' => 'معاملات دستی',
            'accounting_accounts' => 'درخت حساب‌ها',
            'accounting_vouchers' => 'اسناد حسابداری',
            'accounting_lines' => 'آرتیکل‌های اسناد',
            'shop_partners' => 'شرکا',
            'shop_partner_settlements' => 'تسویه با شرکا',
            'shop_partner_settlement_lines' => 'ردیف‌های تسویه شرکا',
        ],
        'پرسنل' => [
            'shop_employees' => 'کارمندان',
            'employee_payrolls' => 'فیش‌های حقوق',
            'employee_payroll_payments' => 'پرداخت‌های حقوق',
        ],
        'باشگاه هوشمند و پیامک' => [
            'shop_customer_metrics' => 'شاخص‌های مشتری',
            'shop_segment_thresholds' => 'آستانه‌های گروه‌بندی',
            'shop_customer_segments' => 'گروه‌بندی مشتریان',
            'shop_smart_actions' => 'اقدام‌های پیشنهادی',
            'shop_campaigns' => 'کمپین‌ها',
            'shop_campaign_rules' => 'قوانین کمپین',
            'shop_campaign_actions' => 'اقدام‌های کمپین',
            'shop_campaign_runs' => 'اجراهای کمپین',
            'shop_campaign_logs' => 'لاگ کمپین',
            'shop_sms_logs' => 'پیامک‌های ارسالی',
            'sms_package_orders' => 'خرید بستهٔ پیامک',
            'oil_reminder_sms' => 'پیامک یادآوری روغن',
        ],
        'سایر' => [
            'settings' => 'تنظیمات',
            'formal_invoice_seller_profiles' => 'مشخصات فروشنده (فاکتور رسمی)',
            'formal_invoice_buyer_profiles' => 'مشخصات خریدار (فاکتور رسمی)',
            'shop_tables' => 'میز و اتاق',
            'shop_services' => 'خدمات اتاق',
            'table_service_requests' => 'درخواست‌های خدمت',
            'table_pager_calls' => 'پیجر میز',
            'oil_visits' => 'مراجعات روغن',
            'oil_visit_items' => 'اقلام مراجعات روغن',
        ],
    ];

    /**
     * فقط جداولی که در پشتیبان تعریف شده‌اند و روی همین دیتابیس وجود دارند.
     *
     * @return list<array{name: string, label: string, group: string}>
     */
    public static function catalog(): array
    {
        $labels = [];
        $position = 0;
        foreach (self::GROUPS as $group => $tables) {
            foreach ($tables as $name => $label) {
                $labels[$name] = ['label' => $label, 'group' => $group, 'order' => $position++];
            }
        }

        $out = [];
        foreach (ShopBackupTables::definitions() as $def) {
            $name = $def['name'];
            if (! Schema::hasTable($name)) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'label' => $labels[$name]['label'] ?? $name,
                'group' => $labels[$name]['group'] ?? 'سایر',
                'order' => $labels[$name]['order'] ?? PHP_INT_MAX,
            ];
        }

        usort($out, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        return array_map(function ($row) {
            unset($row['order']);

            return $row;
        }, $out);
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_column(self::catalog(), 'name');
    }

    public static function label(string $name): string
    {
        foreach (self::GROUPS as $tables) {
            if (isset($tables[$name])) {
                return $tables[$name];
            }
        }

        return $name;
    }
}
