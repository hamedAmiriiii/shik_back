-- چک‌ها (صادره / دریافتی)
CREATE TABLE IF NOT EXISTS `cheques` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned NOT NULL,
  `type` enum('issued','received') NOT NULL COMMENT 'issued=صادره | received=دریافتی',
  `cheque_number` varchar(64) NOT NULL COMMENT 'شماره چک',
  `bank_name` varchar(255) DEFAULT NULL COMMENT 'نام بانک',
  `payee` varchar(255) DEFAULT NULL COMMENT 'طرف حساب (در وجه / صادرکننده)',
  `amount` decimal(15,2) NOT NULL,
  `issue_date` date DEFAULT NULL COMMENT 'تاریخ صدور',
  `due_date` date NOT NULL COMMENT 'تاریخ سررسید',
  `title` varchar(255) DEFAULT NULL,
  `expense_type` enum('جاری','سرمایه') NOT NULL DEFAULT 'جاری' COMMENT 'فقط برای چک صادره هنگام ثبت هزینه',
  `status` enum('pending','cleared','cancelled') NOT NULL DEFAULT 'pending' COMMENT 'pending=در انتظار | cleared=وصول‌شده | cancelled=باطل',
  `expense_id` bigint unsigned DEFAULT NULL,
  `income_id` bigint unsigned DEFAULT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `note` text,
  `cleared_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cheques_atelier_id_type_status_index` (`atelier_id`,`type`,`status`),
  KEY `cheques_atelier_id_due_date_index` (`atelier_id`,`due_date`),
  KEY `cheques_status_index` (`status`),
  CONSTRAINT `cheques_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cheques_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- درآمدها (مثلاً از وصول چک دریافتی)
CREATE TABLE IF NOT EXISTS `incomes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `atelier_id` bigint unsigned DEFAULT NULL,
  `user_name` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `title` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomes_atelier_id_date_index` (`atelier_id`,`date`),
  CONSTRAINT `incomes_atelier_id_foreign` FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FK درآمد روی چک (بعد از ساخت incomes)
ALTER TABLE `cheques`
  ADD CONSTRAINT `cheques_income_id_foreign` FOREIGN KEY (`income_id`) REFERENCES `incomes` (`id`) ON DELETE SET NULL;
