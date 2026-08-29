-- پرداخت ترکیبی فاکتور/هزینه: نقد + چک + نسیه
-- معادل migration 2026_08_29_120000
-- هر دستور را جدا اجرا کنید. اگر Duplicate آمد همان را رد کنید.

CREATE TABLE IF NOT EXISTS `document_payments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `atelier_id` BIGINT UNSIGNED NOT NULL,
    `invoice_id` BIGINT UNSIGNED NULL,
    `expense_id` BIGINT UNSIGNED NULL,
    `method` VARCHAR(16) NOT NULL COMMENT 'account=نقد | cheque=چک | credit=نسیه',
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `shop_account_id` BIGINT UNSIGNED NULL,
    `cheque_id` BIGINT UNSIGNED NULL,
    `settled` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `document_payments_atelier_id_index` (`atelier_id`),
    KEY `document_payments_invoice_id_index` (`invoice_id`),
    KEY `document_payments_expense_id_index` (`expense_id`),
    KEY `document_payments_shop_account_id_index` (`shop_account_id`),
    KEY `document_payments_cheque_id_index` (`cheque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `document_payments`
    ADD CONSTRAINT `document_payments_invoice_id_foreign`
        FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE;

ALTER TABLE `document_payments`
    ADD CONSTRAINT `document_payments_expense_id_foreign`
        FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE;
