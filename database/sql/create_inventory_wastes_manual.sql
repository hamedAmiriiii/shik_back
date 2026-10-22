-- ثبت ضایعات مواد اولیه و کالای تولیدی با FIFO

CREATE TABLE IF NOT EXISTS `inventory_wastes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(20) NOT NULL,
  `raw_material_id` BIGINT UNSIGNED NULL,
  `produced_good_id` BIGINT UNSIGNED NULL,
  `quantity_kg` DECIMAL(12, 3) NOT NULL,
  `total_cost` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `note` TEXT NULL,
  `wasted_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_wastes_atelier_date_idx` (`atelier_id`, `wasted_at`),
  KEY `inventory_wastes_atelier_kind_idx` (`atelier_id`, `kind`),
  CONSTRAINT `inventory_wastes_atelier_fk` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_wastes_raw_fk` FOREIGN KEY (`raw_material_id`) REFERENCES `raw_materials` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_wastes_good_fk` FOREIGN KEY (`produced_good_id`) REFERENCES `produced_goods` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_waste_consumptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inventory_waste_id` BIGINT UNSIGNED NOT NULL,
  `raw_material_lot_id` BIGINT UNSIGNED NULL,
  `production_id` BIGINT UNSIGNED NULL,
  `quantity_kg` DECIMAL(12, 3) NOT NULL,
  `price_per_kg` DECIMAL(15, 2) NOT NULL,
  `cost` DECIMAL(15, 2) NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `iwc_waste_idx` (`inventory_waste_id`),
  KEY `iwc_lot_idx` (`raw_material_lot_id`),
  KEY `iwc_production_idx` (`production_id`),
  CONSTRAINT `iwc_waste_fk` FOREIGN KEY (`inventory_waste_id`) REFERENCES `inventory_wastes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `iwc_lot_fk` FOREIGN KEY (`raw_material_lot_id`) REFERENCES `raw_material_lots` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `iwc_production_fk` FOREIGN KEY (`production_id`) REFERENCES `productions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
