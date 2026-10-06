-- سیستم بازاریاب‌ها (webinoo-plus.ir/newuser)
-- معادل migration 2026_10_06_100000_create_marketer_tables

CREATE TABLE IF NOT EXISTS `marketers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NULL,
  `phone` VARCHAR(11) NOT NULL,
  `code` VARCHAR(16) NOT NULL,
  `commission_percent` DECIMAL(5,2) NULL,
  `card_number` VARCHAR(32) NULL,
  `sheba` VARCHAR(32) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `admin_note` TEXT NULL,
  `last_login_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketers_phone_unique` (`phone`),
  UNIQUE KEY `marketers_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketer_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketer_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL,
  `last_used_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketer_tokens_token_hash_unique` (`token_hash`),
  CONSTRAINT `marketer_tokens_marketer_id_foreign` FOREIGN KEY (`marketer_id`) REFERENCES `marketers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketer_visits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketer_id` BIGINT UNSIGNED NOT NULL,
  `visitor_id` VARCHAR(64) NOT NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `landing_path` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `marketer_visits_marketer_id_visitor_id_index` (`marketer_id`, `visitor_id`),
  CONSTRAINT `marketer_visits_marketer_id_foreign` FOREIGN KEY (`marketer_id`) REFERENCES `marketers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketer_referrals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketer_id` BIGINT UNSIGNED NOT NULL,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `visitor_id` VARCHAR(64) NULL,
  `first_visit_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketer_referrals_atelier_id_unique` (`atelier_id`),
  KEY `marketer_referrals_user_id_index` (`user_id`),
  CONSTRAINT `marketer_referrals_marketer_id_foreign` FOREIGN KEY (`marketer_id`) REFERENCES `marketers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketer_commissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketer_id` BIGINT UNSIGNED NOT NULL,
  `marketer_referral_id` BIGINT UNSIGNED NOT NULL,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `gateway_payment_id` BIGINT UNSIGNED NOT NULL,
  `purchase_amount_toman` BIGINT UNSIGNED NOT NULL,
  `percent` DECIMAL(5,2) NOT NULL,
  `commission_toman` BIGINT UNSIGNED NOT NULL,
  `description` VARCHAR(255) NULL,
  `purchased_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketer_commissions_gateway_payment_id_unique` (`gateway_payment_id`),
  KEY `marketer_commissions_marketer_referral_id_index` (`marketer_referral_id`),
  KEY `marketer_commissions_atelier_id_index` (`atelier_id`),
  CONSTRAINT `marketer_commissions_marketer_id_foreign` FOREIGN KEY (`marketer_id`) REFERENCES `marketers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketer_payouts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketer_id` BIGINT UNSIGNED NOT NULL,
  `amount_toman` BIGINT UNSIGNED NOT NULL,
  `note` VARCHAR(255) NULL,
  `paid_at` TIMESTAMP NULL,
  `created_by_user_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `marketer_payouts_marketer_id_foreign` FOREIGN KEY (`marketer_id`) REFERENCES `marketers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(64) NOT NULL,
  `value` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketing_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
