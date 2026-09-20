-- پرداخت‌های جزئی تسویه نسیه (چند قسط نقد/کارت روی یک فاکتور قرضی)

CREATE TABLE IF NOT EXISTS `purchase_debt_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_id` BIGINT UNSIGNED NOT NULL,
  `card_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `cash_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0,
  `note` VARCHAR(500) NULL DEFAULT NULL,
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_debt_payments_paid_at_index` (`paid_at`),
  KEY `purchase_debt_payments_purchase_id_paid_at_index` (`purchase_id`, `paid_at`),
  CONSTRAINT `purchase_debt_payments_purchase_id_foreign`
    FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
