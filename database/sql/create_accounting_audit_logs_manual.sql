-- لاگ تغییرات حسابرس در اسناد — same as migration 2026_09_29_110000_create_accounting_audit_logs_table.
-- closed_through = تاریخ بستن دوره‌ای که سند در آن افتاده (NULL یعنی دورهٔ باز).

CREATE TABLE IF NOT EXISTS `accounting_audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(32) NOT NULL,
  `voucher_id` BIGINT UNSIGNED NULL,
  `related_voucher_id` BIGINT UNSIGNED NULL,
  `closed_through` DATE NULL,
  `reason` VARCHAR(1000) NULL,
  `payload` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `accounting_audit_logs_atelier_id_created_at_index` (`atelier_id`, `created_at`),
  KEY `accounting_audit_logs_voucher_id_index` (`voucher_id`),
  KEY `accounting_audit_logs_related_voucher_id_index` (`related_voucher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
