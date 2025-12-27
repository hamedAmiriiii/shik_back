-- SQL برای ایجاد جداول carts و cart_items

-- ایجاد جدول carts
CREATE TABLE IF NOT EXISTS `carts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(255) NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `carts_customer_id_index` (`customer_id`),
  KEY `carts_status_index` (`status`),
  CONSTRAINT `carts_customer_id_foreign` 
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) 
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ایجاد جدول cart_items
CREATE TABLE IF NOT EXISTS `cart_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cart_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  `price` DECIMAL(15, 2) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cart_items_cart_id_index` (`cart_id`),
  KEY `cart_items_product_id_index` (`product_id`),
  CONSTRAINT `cart_items_cart_id_foreign` 
    FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) 
    ON DELETE CASCADE,
  CONSTRAINT `cart_items_product_id_foreign` 
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) 
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

