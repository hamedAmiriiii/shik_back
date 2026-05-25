CREATE TABLE IF NOT EXISTS `purchase_item_returns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `purchase_id` BIGINT UNSIGNED NOT NULL,
  `purchased_product_id` BIGINT UNSIGNED NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `sale_price` DECIMAL(15, 2) NOT NULL,
  `purchase_price` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `return_sale_total` DECIMAL(15, 2) NOT NULL,
  `return_purchase_total` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `phone` VARCHAR(11) NULL,
  `payment_type` VARCHAR(32) NULL,
  `credit_used_refund` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `credit_earned_reversed` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `size` VARCHAR(255) NULL,
  `color` VARCHAR(255) NULL,
  `user_name` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_item_returns_atelier_created` (`atelier_id`, `created_at`),
  CONSTRAINT `purchase_item_returns_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_item_returns_purchase_id_foreign`
    FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_item_returns_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
