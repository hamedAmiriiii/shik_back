-- سند خرید و فروش دستی
-- معادل migration 2026_08_27_120000
-- type: purchase = سند خرید (مثل هزینه) | sale = سند فروش (مثل درآمد)
-- اگر جدول از قبل هست، این فایل را رد کنید.

CREATE TABLE IF NOT EXISTS `manual_trades` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `atelier_id` BIGINT UNSIGNED NOT NULL,
    `shop_account_id` BIGINT UNSIGNED NULL,
    `type` VARCHAR(16) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `amount` DECIMAL(15,2) NOT NULL,
    `date` DATE NOT NULL,
    `user_name` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `manual_trades_atelier_type_date` (`atelier_id`, `type`, `date`),
    CONSTRAINT `manual_trades_atelier_id_fk` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `manual_trades_shop_account_fk` FOREIGN KEY (`shop_account_id`) REFERENCES `shop_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
