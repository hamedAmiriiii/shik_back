-- لاگ تغییرات شرکا / سهم / پرداخت سود — same as migration 2026_10_25_100000_create_shop_partner_activity_logs_table.

CREATE TABLE IF NOT EXISTS `shop_partner_activity_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `user_name` VARCHAR(255) NULL,
  `action` VARCHAR(40) NOT NULL,
  `partner_id` BIGINT UNSIGNED NULL,
  `settlement_id` BIGINT UNSIGNED NULL,
  `message` VARCHAR(1000) NULL,
  `payload` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `shop_partner_activity_logs_atelier_id_created_at_index` (`atelier_id`, `created_at`),
  KEY `shop_partner_activity_logs_partner_id_index` (`partner_id`),
  KEY `shop_partner_activity_logs_settlement_id_index` (`settlement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
