-- محصولات تعویض روغن (روغن / فیلتر هوا / فیلتر روغن) و اقلام هر مراجعه
-- معادل migration 2026_09_02_160000_create_oil_products_tables

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `oil_products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned NOT NULL,
  `kind` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oil_products_atelier_kind_active_index` (`atelier_id`, `kind`, `is_active`),
  CONSTRAINT `oil_products_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oil_visit_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `oil_visit_id` bigint unsigned NOT NULL,
  `oil_product_id` bigint unsigned DEFAULT NULL,
  `kind` varchar(32) NOT NULL,
  `product_name` varchar(120) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `oil_visit_items_visit_kind_unique` (`oil_visit_id`, `kind`),
  KEY `oil_visit_items_oil_product_id_foreign` (`oil_product_id`),
  CONSTRAINT `oil_visit_items_oil_visit_id_foreign` FOREIGN KEY (`oil_visit_id`) REFERENCES `oil_visits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `oil_visit_items_oil_product_id_foreign` FOREIGN KEY (`oil_product_id`) REFERENCES `oil_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
