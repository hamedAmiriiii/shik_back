-- پیجر میز (معادل 2026_10_06_143000)

CREATE TABLE IF NOT EXISTS `table_pager_calls` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `atelier_id` BIGINT UNSIGNED NOT NULL,
    `shop_table_id` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `note` VARCHAR(500) NULL,
    `acknowledged_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `table_pager_calls_atelier_status_index` (`atelier_id`, `status`),
    INDEX `table_pager_calls_table_status_index` (`shop_table_id`, `status`),
    CONSTRAINT `table_pager_calls_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `table_pager_calls_shop_table_id_foreign` FOREIGN KEY (`shop_table_id`) REFERENCES `shop_tables` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
