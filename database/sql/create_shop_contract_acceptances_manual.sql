-- قرارداد استفاده از وبینو (نسخه ۱)
-- معادل migration 2026_10_11_120000
-- اگر migration را اجرا کرده‌اید، فقط «بخش ۲» (پر کردن مشتریان قبلی) را اجرا کنید.

-- ============ بخش ۱: ساخت جدول ============
CREATE TABLE IF NOT EXISTS `shop_contract_acceptances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `shop_name` varchar(255) DEFAULT NULL,
  `contract_version` varchar(20) NOT NULL,
  `method` varchar(32) NOT NULL DEFAULT 'sms_otp',
  `ip` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_contract_acceptances_atelier_version_unique` (`atelier_id`, `contract_version`),
  KEY `shop_contract_acceptances_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ بخش ۲: مشتریان قبلی (قرارداد را تأییدشده فرض کن) ============
-- فقط فروشگاه‌هایی که تا این لحظه ساخته شده‌اند؛ فروشگاه‌های جدید باید قرارداد را تأیید کنند.
-- اجرای مجدد بی‌خطر است (فروشگاهی که قبلاً ردیف دارد تکرار نمی‌شود).
INSERT INTO `shop_contract_acceptances`
  (`atelier_id`, `user_id`, `phone`, `shop_name`, `contract_version`, `method`, `accepted_at`, `created_at`, `updated_at`)
SELECT
  a.`id`,
  u.`id`,
  u.`phone`,
  a.`name`,
  '1',
  'legacy_backfill',
  NOW(), NOW(), NOW()
FROM `ateliers` a
LEFT JOIN `users` u
  ON u.`id` = (
    SELECT u2.`id` FROM `users` u2
    WHERE u2.`atelier_id` = a.`id`
    ORDER BY (u2.`shop_staff_role` = 'owner') DESC, u2.`id` ASC
    LIMIT 1
  )
WHERE NOT EXISTS (
  SELECT 1 FROM `shop_contract_acceptances` c
  WHERE c.`atelier_id` = a.`id` AND c.`contract_version` = '1'
);
