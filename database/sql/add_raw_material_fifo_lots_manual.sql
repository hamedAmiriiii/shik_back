-- اگر نسخه قبلی (قیمت واحد روی خود ماده) را اجرا کرده‌اید، این فایل را بزنید.
-- لات‌های FIFO + جدول تولید. قیمت/موجودی از روی لات‌ها محاسبه می‌شود.

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

INSERT INTO `raw_material_lots` (
    `atelier_id`, `raw_material_id`, `quantity_kg`, `remaining_kg`, `price_per_kg`, `purchased_at`, `created_at`, `updated_at`
)
SELECT
    `atelier_id`,
    `id`,
    `stock_kg`,
    `stock_kg`,
    `price_per_kg`,
    COALESCE(`created_at`, NOW()),
    NOW(),
    NOW()
FROM `raw_materials`
WHERE `stock_kg` > 0
  AND NOT EXISTS (
      SELECT 1 FROM `raw_material_lots` l WHERE l.`raw_material_id` = `raw_materials`.`id`
  );

-- اگر ستون‌ها هنوز هستند:
-- ALTER TABLE `raw_materials` DROP COLUMN `price_per_kg`, DROP COLUMN `stock_kg`;

CREATE TABLE IF NOT EXISTS `productions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `atelier_id` BIGINT UNSIGNED NOT NULL,
    `produced_good_id` BIGINT UNSIGNED NOT NULL,
    `quantity_kg` DECIMAL(12,3) NOT NULL,
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
