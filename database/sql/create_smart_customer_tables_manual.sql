-- باشگاه هوشمند (Smart Customer): RFM، سگمنت، Action Engine، Campaign Engine
-- معادل migration: 2026_09_17_200000_create_smart_customer_tables

CREATE TABLE IF NOT EXISTS `shop_customer_metrics` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `phone` VARCHAR(11) NOT NULL,
  `recency_days` INT UNSIGNED NOT NULL DEFAULT 0,
  `frequency` INT UNSIGNED NOT NULL DEFAULT 0,
  `monetary` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `avg_days_between` DECIMAL(10,2) NULL,
  `first_purchase_at` TIMESTAMP NULL DEFAULT NULL,
  `last_purchase_at` TIMESTAMP NULL DEFAULT NULL,
  `purchase_count_30d` INT UNSIGNED NOT NULL DEFAULT 0,
  `purchase_count_90d` INT UNSIGNED NOT NULL DEFAULT 0,
  `monetary_30d` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `monetary_90d` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `avg_order_value` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `computed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_customer_metrics_atelier_phone_uq` (`atelier_id`, `phone`),
  KEY `shop_customer_metrics_atelier_recency_idx` (`atelier_id`, `recency_days`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_segment_thresholds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `metrics_window` VARCHAR(16) NOT NULL DEFAULT 'all',
  `vip_max_recency_days` INT UNSIGNED NOT NULL DEFAULT 30,
  `vip_min_frequency` INT UNSIGNED NOT NULL DEFAULT 8,
  `vip_min_monetary` DECIMAL(15,2) NOT NULL DEFAULT 15000000,
  `loyal_max_recency_days` INT UNSIGNED NOT NULL DEFAULT 45,
  `loyal_min_frequency` INT UNSIGNED NOT NULL DEFAULT 4,
  `new_max_days_since_first` INT UNSIGNED NOT NULL DEFAULT 21,
  `new_max_frequency` INT UNSIGNED NOT NULL DEFAULT 2,
  `growing_min_purchases_90d` INT UNSIGNED NOT NULL DEFAULT 3,
  `at_risk_recency_multiplier` DECIMAL(5,2) NOT NULL DEFAULT 1.50,
  `at_risk_min_recency_days` INT UNSIGNED NOT NULL DEFAULT 35,
  `at_risk_min_frequency` INT UNSIGNED NOT NULL DEFAULT 3,
  `inactive_min_recency_days` INT UNSIGNED NOT NULL DEFAULT 60,
  `churned_min_recency_days` INT UNSIGNED NOT NULL DEFAULT 120,
  `high_value_min_monetary` DECIMAL(15,2) NOT NULL DEFAULT 10000000,
  `low_value_max_monetary` DECIMAL(15,2) NOT NULL DEFAULT 1000000,
  `near_vip_frequency_gap` INT UNSIGNED NOT NULL DEFAULT 2,
  `action_cooldown_days` INT UNSIGNED NOT NULL DEFAULT 4,
  `winback_credit_amount` DECIMAL(15,2) NOT NULL DEFAULT 100000,
  `winback_revenue_factor` DECIMAL(5,2) NOT NULL DEFAULT 0.35,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_segment_thresholds_atelier_id_unique` (`atelier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_customer_segments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `phone` VARCHAR(11) NOT NULL,
  `primary_segment` VARCHAR(32) NOT NULL,
  `tags` JSON NULL,
  `rfm_scores` JSON NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_customer_segments_atelier_phone_uq` (`atelier_id`, `phone`),
  KEY `shop_customer_segments_atelier_seg_idx` (`atelier_id`, `primary_segment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_smart_actions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `phone` VARCHAR(11) NOT NULL,
  `action_type` VARCHAR(64) NOT NULL,
  `priority` TINYINT UNSIGNED NOT NULL DEFAULT 50,
  `title` VARCHAR(191) NOT NULL,
  `reason` TEXT NULL,
  `payload` JSON NULL,
  `estimated_revenue` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(24) NOT NULL DEFAULT 'suggested',
  `source` VARCHAR(24) NOT NULL DEFAULT 'system',
  `campaign_id` BIGINT UNSIGNED NULL,
  `suggested_send_at` TIMESTAMP NULL DEFAULT NULL,
  `expires_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_smart_actions_status_idx` (`atelier_id`, `status`, `priority`),
  KEY `shop_smart_actions_phone_type_idx` (`atelier_id`, `phone`, `action_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_campaigns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'draft',
  `trigger` VARCHAR(24) NOT NULL DEFAULT 'manual',
  `cooldown_days` INT UNSIGNED NOT NULL DEFAULT 4,
  `max_recipients_per_run` INT UNSIGNED NULL,
  `daily_sms_budget` INT UNSIGNED NULL,
  `require_manual_approve` TINYINT(1) NOT NULL DEFAULT 1,
  `description` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_campaigns_atelier_status_idx` (`atelier_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_campaign_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `conditions` JSON NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `shop_campaign_rules_campaign_id_foreign`
    FOREIGN KEY (`campaign_id`) REFERENCES `shop_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_campaign_actions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `sort` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `type` VARCHAR(32) NOT NULL,
  `config` JSON NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `shop_campaign_actions_campaign_id_foreign`
    FOREIGN KEY (`campaign_id`) REFERENCES `shop_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_campaign_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `trigger` VARCHAR(24) NOT NULL DEFAULT 'manual',
  `matched_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `sent_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `estimated_revenue` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(24) NOT NULL DEFAULT 'completed',
  `error_message` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_campaign_runs_atelier_campaign_idx` (`atelier_id`, `campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_campaign_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `run_id` BIGINT UNSIGNED NULL,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `phone` VARCHAR(11) NOT NULL,
  `status` VARCHAR(24) NOT NULL,
  `skip_reason` VARCHAR(64) NULL,
  `actions_result` JSON NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_campaign_logs_campaign_phone_idx` (`campaign_id`, `phone`),
  KEY `shop_campaign_logs_run_idx` (`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
