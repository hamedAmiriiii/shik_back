-- شرکا، سرمایه، و تاریخچه تقسیم سود
CREATE TABLE IF NOT EXISTS `shop_partners` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(20) NULL,
  `capital_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_partners_atelier_active` (`atelier_id`, `is_active`),
  CONSTRAINT `shop_partners_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_partner_settlements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `settled_at` DATE NOT NULL,
  `period_from` DATE NULL,
  `period_to` DATE NOT NULL,
  `net_profit` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `total_distributed` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `shop_account_id` BIGINT UNSIGNED NULL,
  `user_name` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_partner_settlements_atelier_settled` (`atelier_id`, `settled_at`),
  CONSTRAINT `shop_partner_settlements_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_partner_settlement_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `settlement_id` BIGINT UNSIGNED NOT NULL,
  `partner_id` BIGINT UNSIGNED NULL,
  `partner_name` VARCHAR(255) NOT NULL,
  `capital_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `share_percent` DECIMAL(8, 4) NOT NULL DEFAULT 0,
  `amount` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_partner_settlement_lines_settlement` (`settlement_id`),
  CONSTRAINT `shop_partner_settlement_lines_settlement_id_foreign`
    FOREIGN KEY (`settlement_id`) REFERENCES `shop_partner_settlements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
