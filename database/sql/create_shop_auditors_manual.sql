-- Shop auditors — same as migration 2026_09_29_100000_create_shop_auditors_table.
-- One auditor user (users.shop_staff_role = 'auditor') can be linked to many shops, read-only.
-- users.atelier_id of an auditor = the shop currently selected by that auditor.

CREATE TABLE IF NOT EXISTS `shop_auditors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `permissions` JSON NULL,
  `note` VARCHAR(2000) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_auditors_atelier_id_user_id_unique` (`atelier_id`, `user_id`),
  KEY `shop_auditors_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
