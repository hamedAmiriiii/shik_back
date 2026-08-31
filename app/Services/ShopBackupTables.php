<?php

namespace App\Services;

/**
 * فهرست جداول پشتیبان فروشگاه.
 * ترتیب: والد قبل از فرزند (درج). حذف برعکس همین فهرست است.
 */
class ShopBackupTables
{
    public const FORMAT = 'atelier-shop-backup';

    public const VERSION = 2;

    /**
     * تنظیماتی که متعلق به پلتفرم است و با بازگردانی عوض نمی‌شود.
     */
    public const PRESERVED_SETTING_KEYS = [
        'shop_sms_quota',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'shop_accounts',
                'scope' => 'atelier',
                'fks' => [],
            ],
            [
                'name' => 'accounting_accounts',
                'scope' => 'atelier',
                'fks' => [
                    'parent_id' => 'accounting_accounts',
                    'linked_id' => 'shop_accounts',
                ],
            ],
            [
                'name' => 'accounting_vouchers',
                'scope' => 'atelier',
                'fks' => [
                    'reverses_voucher_id' => 'accounting_vouchers',
                ],
                'skip_columns' => ['created_by'],
            ],
            [
                'name' => 'accounting_lines',
                'scope' => 'parent',
                'parent' => 'accounting_vouchers',
                'parent_key' => 'voucher_id',
                'fks' => [
                    'voucher_id' => 'accounting_vouchers',
                    'account_id' => 'accounting_accounts',
                ],
            ],
            [
                'name' => 'settings',
                'scope' => 'atelier',
                'fks' => [],
                'preserve_keys' => self::PRESERVED_SETTING_KEYS,
            ],
            [
                'name' => 'shop_loyalty_credit_tiers',
                'scope' => 'atelier',
                'fks' => [],
            ],
            [
                'name' => 'manufacturers',
                'scope' => 'atelier',
                'fks' => [],
            ],
            [
                'name' => 'categories',
                'scope' => 'atelier',
                'fks' => [
                    'parent_id' => 'categories',
                ],
                'files' => ['image'],
            ],
            [
                'name' => 'products',
                'scope' => 'atelier',
                'fks' => [
                    'manufacturer_id' => 'manufacturers',
                ],
            ],
            [
                'name' => 'product_images',
                'scope' => 'parent',
                'parent' => 'products',
                'parent_key' => 'product_id',
                'fks' => [
                    'product_id' => 'products',
                ],
                'files' => ['image_path'],
            ],
            [
                'name' => 'category_product',
                'scope' => 'parent',
                'parent' => 'products',
                'parent_key' => 'product_id',
                'fks' => [
                    'category_id' => 'categories',
                    'product_id' => 'products',
                ],
            ],
            [
                'name' => 'raw_materials',
                'scope' => 'atelier',
                'fks' => [],
            ],
            [
                'name' => 'produced_goods',
                'scope' => 'atelier',
                'fks' => [],
            ],
            [
                'name' => 'category_produced_good',
                'scope' => 'parent',
                'parent' => 'produced_goods',
                'parent_key' => 'produced_good_id',
                'fks' => [
                    'category_id' => 'categories',
                    'produced_good_id' => 'produced_goods',
                ],
            ],
            [
                'name' => 'produced_good_ingredients',
                'scope' => 'parent',
                'parent' => 'produced_goods',
                'parent_key' => 'produced_good_id',
                'fks' => [
                    'produced_good_id' => 'produced_goods',
                    'raw_material_id' => 'raw_materials',
                ],
            ],
            [
                'name' => 'shop_employees',
                'scope' => 'atelier',
                'fks' => [],
                'skip_columns' => ['user_id'],
            ],
            [
                'name' => 'shop_tables',
                'scope' => 'atelier',
                'fks' => [],
            ],
            [
                'name' => 'customers',
                'scope' => 'atelier',
                'fks' => [],
            ],
            [
                'name' => 'customer_addresses',
                'scope' => 'parent',
                'parent' => 'customers',
                'parent_key' => 'customer_id',
                'fks' => [
                    'customer_id' => 'customers',
                ],
            ],
            [
                'name' => 'user_shiksho',
                'scope' => 'atelier',
                'fks' => [],
            ],
            [
                'name' => 'carts',
                'scope' => 'atelier',
                'fks' => [
                    'customer_id' => 'customers',
                    'address_id' => 'customer_addresses',
                ],
            ],
            [
                'name' => 'cart_items',
                'scope' => 'parent',
                'parent' => 'carts',
                'parent_key' => 'cart_id',
                'fks' => [
                    'cart_id' => 'carts',
                    'product_id' => 'products',
                ],
            ],
            [
                'name' => 'invoices',
                'scope' => 'atelier',
                'fks' => [
                    'shop_account_id' => 'shop_accounts',
                    'cheque_id' => 'cheques',
                    'beneficiary_id' => 'user_shiksho',
                ],
                'files' => ['image_path'],
            ],
            [
                'name' => 'invoice_items',
                'scope' => 'parent',
                'parent' => 'invoices',
                'parent_key' => 'invoice_id',
                'fks' => [
                    'invoice_id' => 'invoices',
                ],
            ],
            [
                'name' => 'expenses',
                'scope' => 'atelier',
                'fks' => [
                    'shop_account_id' => 'shop_accounts',
                    'cheque_id' => 'cheques',
                    'beneficiary_id' => 'user_shiksho',
                ],
            ],
            [
                'name' => 'incomes',
                'scope' => 'atelier',
                'fks' => [],
            ],
            [
                'name' => 'cheques',
                'scope' => 'atelier',
                'fks' => [
                    'shop_account_id' => 'shop_accounts',
                    'purchase_id' => 'purchases',
                    'invoice_id' => 'invoices',
                    'expense_id' => 'expenses',
                    'income_id' => 'incomes',
                ],
            ],
            [
                'name' => 'document_payments',
                'scope' => 'atelier',
                'fks' => [
                    'invoice_id' => 'invoices',
                    'expense_id' => 'expenses',
                    'shop_account_id' => 'shop_accounts',
                    'cheque_id' => 'cheques',
                ],
            ],
            [
                'name' => 'raw_material_lots',
                'scope' => 'atelier',
                'fks' => [
                    'raw_material_id' => 'raw_materials',
                    'invoice_id' => 'invoices',
                    'invoice_item_id' => 'invoice_items',
                ],
            ],
            [
                'name' => 'productions',
                'scope' => 'atelier',
                'fks' => [
                    'produced_good_id' => 'produced_goods',
                ],
            ],
            [
                'name' => 'production_consumptions',
                'scope' => 'parent',
                'parent' => 'productions',
                'parent_key' => 'production_id',
                'fks' => [
                    'production_id' => 'productions',
                    'raw_material_id' => 'raw_materials',
                    'raw_material_lot_id' => 'raw_material_lots',
                ],
            ],
            [
                'name' => 'purchases',
                'scope' => 'atelier',
                'fks' => [
                    'cart_id' => 'carts',
                    'cheque_id' => 'cheques',
                    'shop_table_id' => 'shop_tables',
                ],
            ],
            [
                'name' => 'purchased_products',
                'scope' => 'parent',
                'parent' => 'purchases',
                'parent_key' => 'purchase_id',
                'fks' => [
                    'purchase_id' => 'purchases',
                    'product_id' => 'products',
                    'produced_good_id' => 'produced_goods',
                    'raw_material_id' => 'raw_materials',
                ],
            ],
            [
                'name' => 'purchase_stock_consumptions',
                'scope' => 'parent',
                'parent' => 'purchased_products',
                'parent_key' => 'purchased_product_id',
                'fks' => [
                    'purchased_product_id' => 'purchased_products',
                    'production_id' => 'productions',
                    'raw_material_lot_id' => 'raw_material_lots',
                ],
            ],
            [
                'name' => 'installments',
                'scope' => 'parent',
                'parent' => 'purchases',
                'parent_key' => 'purchase_id',
                'fks' => [
                    'purchase_id' => 'purchases',
                ],
            ],
            [
                'name' => 'user_credit_grants',
                'scope' => 'atelier',
                'fks' => [
                    'purchase_id' => 'purchases',
                ],
            ],
            [
                'name' => 'purchase_item_returns',
                'scope' => 'atelier',
                'fks' => [
                    'purchase_id' => 'purchases',
                    'purchased_product_id' => 'purchased_products',
                    'product_id' => 'products',
                    'produced_good_id' => 'produced_goods',
                    'raw_material_id' => 'raw_materials',
                ],
            ],
            [
                'name' => 'returned_products',
                'scope' => 'atelier',
                'fks' => [
                    'product_id' => 'products',
                    'purchase_id' => 'purchases',
                ],
            ],
            [
                'name' => 'table_orders',
                'scope' => 'atelier',
                'fks' => [
                    'shop_table_id' => 'shop_tables',
                    'purchase_id' => 'purchases',
                ],
                'files' => ['receipt_path'],
            ],
            [
                'name' => 'table_order_items',
                'scope' => 'parent',
                'parent' => 'table_orders',
                'parent_key' => 'table_order_id',
                'fks' => [
                    'table_order_id' => 'table_orders',
                    'product_id' => 'products',
                ],
            ],
            [
                'name' => 'employee_payrolls',
                'scope' => 'atelier',
                'fks' => [
                    'shop_employee_id' => 'shop_employees',
                    'expense_id' => 'expenses',
                ],
                'skip_columns' => ['paid_by_user_id'],
            ],
            [
                'name' => 'employee_payroll_payments',
                'scope' => 'atelier',
                'fks' => [
                    'payroll_id' => 'employee_payrolls',
                    'expense_id' => 'expenses',
                ],
                'skip_columns' => ['paid_by_user_id'],
            ],
            [
                'name' => 'daily_shop_reconciliation_deposits',
                'scope' => 'atelier',
                'fks' => [
                    'shop_account_id' => 'shop_accounts',
                ],
            ],
            [
                'name' => 'daily_shop_reconciliations',
                'scope' => 'atelier',
                'fks' => [
                    'deposit_record_account_1_id' => 'daily_shop_reconciliation_deposits',
                    'deposit_record_account_2_id' => 'daily_shop_reconciliation_deposits',
                    'deposit_record_cash_id' => 'daily_shop_reconciliation_deposits',
                ],
            ],
            [
                'name' => 'daily_shop_reconciliation_account_deposits',
                'scope' => 'parent',
                'parent' => 'daily_shop_reconciliations',
                'parent_key' => 'reconciliation_id',
                'fks' => [
                    'reconciliation_id' => 'daily_shop_reconciliations',
                    'shop_account_id' => 'shop_accounts',
                    'deposit_record_id' => 'daily_shop_reconciliation_deposits',
                ],
            ],
            [
                'name' => 'shop_account_transfers',
                'scope' => 'atelier',
                'fks' => [
                    'from_shop_account_id' => 'shop_accounts',
                    'to_shop_account_id' => 'shop_accounts',
                ],
            ],
            [
                'name' => 'manual_trades',
                'scope' => 'atelier',
                'fks' => [
                    'shop_account_id' => 'shop_accounts',
                ],
            ],
            [
                'name' => 'shop_sms_logs',
                'scope' => 'atelier',
                'fks' => [
                    'purchase_id' => 'purchases',
                ],
            ],
            [
                'name' => 'sms_package_orders',
                'scope' => 'atelier',
                'fks' => [],
                'restore' => false,
                'skip_columns' => ['requested_by_user_id', 'reviewed_by_user_id'],
            ],
        ];
    }
}
