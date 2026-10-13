-- پیش‌پرداخت ذینفع از بیش‌پرداخت ظهرنویسی چک
-- هر دستور را یک‌بار اجرا کنید.

CREATE TABLE IF NOT EXISTS `beneficiary_advances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `beneficiary_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `remaining` DECIMAL(15,2) NOT NULL,
  `source` VARCHAR(32) NOT NULL DEFAULT 'cheque_transfer',
  `cheque_id` BIGINT UNSIGNED NULL,
  `settled_invoice_id` BIGINT UNSIGNED NULL,
  `settled_expense_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'open',
  `note` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `beneficiary_advances_atelier_beneficiary_status_index` (`atelier_id`, `beneficiary_id`, `status`),
  KEY `beneficiary_advances_cheque_id_index` (`cheque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `beneficiary_advance_applications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `advance_id` BIGINT UNSIGNED NOT NULL,
  `invoice_id` BIGINT UNSIGNED NULL,
  `expense_id` BIGINT UNSIGNED NULL,
  `document_payment_id` BIGINT UNSIGNED NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `beneficiary_advance_applications_advance_id_index` (`advance_id`),
  KEY `beneficiary_advance_applications_invoice_id_index` (`invoice_id`),
  KEY `beneficiary_advance_applications_expense_id_index` (`expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- حساب معین پیش‌پرداخت به تأمین‌کننده (اگر از قبل نیست؛ atelier_id را جایگزین کنید یا از طریق کدینگ خودکار ساخته می‌شود)
-- INSERT فقط در صورت نیاز دستی؛ معمولاً با اولین سند ظهرنویسی از blueprint اضافه می‌شود.
