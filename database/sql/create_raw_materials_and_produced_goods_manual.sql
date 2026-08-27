-- ========================================================
-- انبار مواد اولیه FIFO + کالاهای تولیدی و فرمول ساخت
-- معادل migrationهای 2026_08_23_100000 و 110000
-- مستقل از جدول products
-- قیمت هر خرید جداست؛ مصرف تولید از قدیمی‌ترین لات
-- ========================================================

CREATE TABLE IF NOT EXISTS `raw_materials` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `atelier_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `sale_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `note` TEXT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `raw_materials_atelier_name_unique` (`atelier_id`, `name`),
    KEY `raw_materials_atelier_name_index` (`atelier_id`, `name`),
    CONSTRAINT `raw_materials_atelier_id_fk` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `raw_material_lots` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `atelier_id` BIGINT UNSIGNED NOT NULL,
    `raw_material_id` BIGINT UNSIGNED NOT NULL,
    `quantity_kg` DECIMAL(12,3) NOT NULL,
    `remaining_kg` DECIMAL(12,3) NOT NULL,
    `price_per_kg` DECIMAL(15,2) NOT NULL,
    `purchased_at` TIMESTAMP NULL,
    `note` TEXT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `raw_material_lots_fifo` (`raw_material_id`, `purchased_at`, `id`),
    KEY `raw_material_lots_atelier_material` (`atelier_id`, `raw_material_id`),
    CONSTRAINT `raw_material_lots_atelier_id_fk` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `raw_material_lots_material_fk` FOREIGN KEY (`raw_material_id`) REFERENCES `raw_materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `produced_goods` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `atelier_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `sale_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `markup_percent` DECIMAL(8,2) NULL,
    `round_sale_price` TINYINT(1) NOT NULL DEFAULT 0,
    `note` TEXT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `produced_goods_atelier_name_unique` (`atelier_id`, `name`),
    KEY `produced_goods_atelier_name_index` (`atelier_id`, `name`),
    CONSTRAINT `produced_goods_atelier_id_fk` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `produced_good_ingredients` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `produced_good_id` BIGINT UNSIGNED NOT NULL,
    `raw_material_id` BIGINT UNSIGNED NOT NULL,
    `grams_per_kg` DECIMAL(12,3) NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `produced_good_ingredients_unique` (`produced_good_id`, `raw_material_id`),
    KEY `produced_good_ingredients_raw_material_id` (`raw_material_id`),
    CONSTRAINT `produced_good_ingredients_good_fk` FOREIGN KEY (`produced_good_id`) REFERENCES `produced_goods` (`id`) ON DELETE CASCADE,
    CONSTRAINT `produced_good_ingredients_material_fk` FOREIGN KEY (`raw_material_id`) REFERENCES `raw_materials` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `productions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `atelier_id` BIGINT UNSIGNED NOT NULL,
    `produced_good_id` BIGINT UNSIGNED NOT NULL,
    `quantity_kg` DECIMAL(12,3) NOT NULL,
    `remaining_kg` DECIMAL(12,3) NOT NULL DEFAULT 0,
    `total_cost` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `cost_per_kg` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `note` TEXT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `productions_atelier_good` (`atelier_id`, `produced_good_id`),
    CONSTRAINT `productions_atelier_id_fk` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `productions_good_fk` FOREIGN KEY (`produced_good_id`) REFERENCES `produced_goods` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `production_consumptions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `production_id` BIGINT UNSIGNED NOT NULL,
    `raw_material_id` BIGINT UNSIGNED NOT NULL,
    `raw_material_lot_id` BIGINT UNSIGNED NOT NULL,
    `quantity_kg` DECIMAL(12,3) NOT NULL,
    `price_per_kg` DECIMAL(15,2) NOT NULL,
    `cost` DECIMAL(15,2) NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `production_consumptions_production_id` (`production_id`),
    KEY `production_consumptions_lot_id` (`raw_material_lot_id`),
    CONSTRAINT `production_consumptions_production_fk` FOREIGN KEY (`production_id`) REFERENCES `productions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `production_consumptions_material_fk` FOREIGN KEY (`raw_material_id`) REFERENCES `raw_materials` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `production_consumptions_lot_fk` FOREIGN KEY (`raw_material_lot_id`) REFERENCES `raw_material_lots` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
