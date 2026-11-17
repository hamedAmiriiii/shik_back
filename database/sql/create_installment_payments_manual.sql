-- پرداخت جزئی/چندمرحله‌ای اقساط (برای ثبت سند حسابداری یکتا به‌ازای هر پرداخت)

CREATE TABLE IF NOT EXISTS `installment_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_id` BIGINT UNSIGNED NOT NULL,
  `installment_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15, 2) NOT NULL,
  `notes` VARCHAR(1000) NULL DEFAULT NULL,
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `installment_payments_paid_at_index` (`paid_at`),
  KEY `installment_payments_purchase_id_paid_at_index` (`purchase_id`, `paid_at`),
  CONSTRAINT `installment_payments_purchase_id_foreign`
    FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `installment_payments_installment_id_foreign`
    FOREIGN KEY (`installment_id`) REFERENCES `installments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
