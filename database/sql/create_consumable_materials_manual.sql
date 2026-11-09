-- انبار مواد مصرفی با لات FIFO و هیستوری برداشت

CREATE TABLE IF NOT EXISTS `consumable_materials` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `note` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `consumable_materials_atelier_name_unique` (`atelier_id`, `name`),
  KEY `consumable_materials_atelier_name_idx` (`atelier_id`, `name`),
  CONSTRAINT `consumable_materials_atelier_fk` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `consumable_material_lots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `consumable_material_id` BIGINT UNSIGNED NOT NULL,
  `quantity` DECIMAL(12, 3) NOT NULL,
  `remaining` DECIMAL(12, 3) NOT NULL,
  `unit_cost` DECIMAL(15, 2) NOT NULL,
  `purchased_at` TIMESTAMP NULL,
  `invoice_id` BIGINT UNSIGNED NULL,
  `invoice_item_id` BIGINT UNSIGNED NULL,
  `note` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `consumable_lots_fifo` (`consumable_material_id`, `purchased_at`, `id`),
  KEY `consumable_lots_atelier_material` (`atelier_id`, `consumable_material_id`),
  KEY `consumable_lots_invoice` (`invoice_id`),
  CONSTRAINT `consumable_lots_atelier_fk` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `consumable_lots_material_fk` FOREIGN KEY (`consumable_material_id`) REFERENCES `consumable_materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `consumable_lots_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `consumable_lots_invoice_item_fk` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `consumable_withdrawals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `consumable_material_id` BIGINT UNSIGNED NOT NULL,
  `quantity` DECIMAL(12, 3) NOT NULL,
  `total_cost` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `withdrawn_at` TIMESTAMP NULL,
  `note` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `consumable_withdrawals_atelier_date` (`atelier_id`, `withdrawn_at`),
  KEY `consumable_withdrawals_material` (`consumable_material_id`),
  CONSTRAINT `consumable_withdrawals_atelier_fk` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `consumable_withdrawals_material_fk` FOREIGN KEY (`consumable_material_id`) REFERENCES `consumable_materials` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `consumable_withdrawal_slices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `consumable_withdrawal_id` BIGINT UNSIGNED NOT NULL,
  `consumable_material_lot_id` BIGINT UNSIGNED NOT NULL,
  `quantity` DECIMAL(12, 3) NOT NULL,
  `unit_cost` DECIMAL(15, 2) NOT NULL,
  `cost` DECIMAL(15, 2) NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `cws_withdrawal_idx` (`consumable_withdrawal_id`),
  KEY `cws_lot_idx` (`consumable_material_lot_id`),
  CONSTRAINT `cws_withdrawal_fk` FOREIGN KEY (`consumable_withdrawal_id`) REFERENCES `consumable_withdrawals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cws_lot_fk` FOREIGN KEY (`consumable_material_lot_id`) REFERENCES `consumable_material_lots` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ارزش افزوده هر ردیف فاکتور + تخفیف کلی فاکتور (سرشکن روی قیمت تمام‌شده کالا)
ALTER TABLE `invoice_items`
  ADD COLUMN `vat_mode` VARCHAR(16) NOT NULL DEFAULT 'none' AFTER `total`,
  ADD COLUMN `vat_percent` DECIMAL(8, 2) NULL AFTER `vat_mode`,
  ADD COLUMN `vat_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `vat_percent`;

ALTER TABLE `invoices`
  ADD COLUMN `discount_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `vat_amount`;
