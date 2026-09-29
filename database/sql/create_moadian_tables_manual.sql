-- Moadian (Iranian tax e-invoice) tables — same as migration 2026_09_28_200000_create_moadian_tables.
-- Design: docs/moadian-integration-design.md
-- Only new tables plus one nullable column on `products`; nothing existing changes behaviour.

CREATE TABLE IF NOT EXISTS `moadian_shop_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `environment` VARCHAR(20) NOT NULL DEFAULT 'sandbox',
  `connection_mode` VARCHAR(20) NOT NULL DEFAULT 'self_tsp',
  `memory_id` VARCHAR(20) NULL,
  `private_key` TEXT NULL,
  `certificate` TEXT NULL,
  `tsp_provider` VARCHAR(100) NULL,
  `tsp_credentials` TEXT NULL,
  `price_includes_vat` TINYINT(1) NOT NULL DEFAULT 1,
  `default_invoice_type` TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `auto_type1_with_buyer` TINYINT(1) NOT NULL DEFAULT 1,
  `default_vat_rate` DECIMAL(5, 2) NOT NULL DEFAULT 10,
  `default_sstid` VARCHAR(13) NULL,
  `default_sstt` VARCHAR(200) NULL,
  `unit_code_piece` VARCHAR(10) NULL,
  `unit_code_kg` VARCHAR(10) NULL,
  `unit_code_meter` VARCHAR(10) NULL,
  `late_threshold_days` SMALLINT UNSIGNED NULL,
  `start_purchase_id` BIGINT UNSIGNED NULL,
  `started_at` TIMESTAMP NULL,
  `paused_reason` TEXT NULL,
  `last_verified_at` TIMESTAMP NULL,
  `last_run_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `moadian_shop_settings_atelier_id_unique` (`atelier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moadian_stuff_ids` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `sstid` VARCHAR(13) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `vat_rate` DECIMAL(5, 2) NOT NULL DEFAULT 0,
  `other_tax_rate` DECIMAL(5, 2) NOT NULL DEFAULT 0,
  `other_tax_subject` VARCHAR(200) NULL,
  `unit_code` VARCHAR(10) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `moadian_stuff_ids_atelier_id_index` (`atelier_id`),
  UNIQUE KEY `moadian_stuff_ids_atelier_id_sstid_unique` (`atelier_id`, `sstid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Skip if the column already exists:
--   SELECT COUNT(*) FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'moadian_sstid';
ALTER TABLE `products`
  ADD COLUMN `moadian_sstid` VARCHAR(13) NULL,
  ADD INDEX `products_moadian_sstid_index` (`moadian_sstid`);

CREATE TABLE IF NOT EXISTS `moadian_serial_counters` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `memory_id` VARCHAR(20) NOT NULL,
  `last_serial` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `moadian_serial_counters_atelier_id_memory_id_unique` (`atelier_id`, `memory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moadian_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `purchase_id` BIGINT UNSIGNED NULL,
  `subject` TINYINT UNSIGNED NOT NULL,
  `invoice_type` TINYINT UNSIGNED NOT NULL,
  `pattern` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `reference_document_id` BIGINT UNSIGNED NULL,
  `reference_taxid` VARCHAR(22) NULL,
  `memory_id` VARCHAR(20) NOT NULL,
  `serial` BIGINT UNSIGNED NOT NULL,
  `inno` VARCHAR(10) NOT NULL,
  `taxid` VARCHAR(22) NOT NULL,
  `indatim` BIGINT UNSIGNED NOT NULL,
  `indati2m` BIGINT UNSIGNED NULL,
  `insr` TINYINT(1) NOT NULL DEFAULT 0,
  `payload` LONGTEXT NOT NULL,
  `source_fingerprint` VARCHAR(64) NULL,
  `tprdis` DECIMAL(20, 0) NOT NULL DEFAULT 0,
  `tdis` DECIMAL(20, 0) NOT NULL DEFAULT 0,
  `tadis` DECIMAL(20, 0) NOT NULL DEFAULT 0,
  `tvam` DECIMAL(20, 0) NOT NULL DEFAULT 0,
  `todam` DECIMAL(20, 0) NOT NULL DEFAULT 0,
  `tbill` DECIMAL(20, 0) NOT NULL DEFAULT 0,
  `setm` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
  `uid` VARCHAR(64) NULL,
  `reference_number` VARCHAR(64) NULL,
  `errors` JSON NULL,
  `warnings` JSON NULL,
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` TIMESTAMP NULL,
  `sent_at` TIMESTAMP NULL,
  `last_inquiry_at` TIMESTAMP NULL,
  `finalized_at` TIMESTAMP NULL,
  `triggered_by` VARCHAR(20) NOT NULL DEFAULT 'sale',
  `user_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `moadian_documents_atelier_id_index` (`atelier_id`),
  KEY `moadian_documents_purchase_id_index` (`purchase_id`),
  KEY `moadian_documents_status_index` (`status`),
  KEY `moadian_documents_uid_index` (`uid`),
  KEY `moadian_documents_reference_number_index` (`reference_number`),
  UNIQUE KEY `moadian_documents_atelier_id_taxid_unique` (`atelier_id`, `taxid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moadian_document_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `moadian_document_id` BIGINT UNSIGNED NOT NULL,
  `line_key` VARCHAR(80) NOT NULL,
  `purchased_product_id` BIGINT UNSIGNED NULL,
  `sstid` VARCHAR(13) NOT NULL,
  `sstt` VARCHAR(400) NULL,
  `mu` VARCHAR(10) NULL,
  `am` DECIMAL(18, 3) NOT NULL,
  `fee` DECIMAL(20, 0) NOT NULL,
  `prdis` DECIMAL(20, 0) NOT NULL,
  `dis` DECIMAL(20, 0) NOT NULL DEFAULT 0,
  `adis` DECIMAL(20, 0) NOT NULL,
  `vra` DECIMAL(5, 2) NOT NULL DEFAULT 0,
  `vam` DECIMAL(20, 0) NOT NULL DEFAULT 0,
  `odr` DECIMAL(5, 2) NOT NULL DEFAULT 0,
  `odam` DECIMAL(20, 0) NOT NULL DEFAULT 0,
  `tsstam` DECIMAL(20, 0) NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `moadian_document_items_moadian_document_id_index` (`moadian_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moadian_sale_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `purchase_id` BIGINT UNSIGNED NOT NULL,
  `head_document_id` BIGINT UNSIGNED NULL,
  `fingerprint` VARCHAR(64) NULL,
  `closed` TINYINT(1) NOT NULL DEFAULT 0,
  `checked_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `moadian_sale_links_atelier_id_index` (`atelier_id`),
  UNIQUE KEY `moadian_sale_links_purchase_id_unique` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moadian_api_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(50) NOT NULL,
  `method` VARCHAR(10) NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `http_status` SMALLINT UNSIGNED NULL,
  `duration_ms` INT UNSIGNED NULL,
  `document_ids` JSON NULL,
  `request` LONGTEXT NULL,
  `response` LONGTEXT NULL,
  `error` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `moadian_api_logs_atelier_id_index` (`atelier_id`),
  KEY `moadian_api_logs_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
