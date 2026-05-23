-- ثبت اعتبارهای دستی (هدیه) برای گزارش حسابداری
CREATE TABLE IF NOT EXISTS `user_credit_grants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned NOT NULL,
  `phone` varchar(11) NOT NULL,
  `credit_type` enum('regular','installment') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `source` varchar(32) NOT NULL DEFAULT 'manual',
  `purchase_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_credit_grants_atelier_created` (`atelier_id`,`created_at`),
  KEY `user_credit_grants_atelier_phone` (`atelier_id`,`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
