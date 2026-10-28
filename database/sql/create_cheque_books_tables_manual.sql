-- دسته چک و برگه‌ها — هر دستور را یک‌بار اجرا کنید؛ خطای Duplicate را نادیده بگیرید.

CREATE TABLE IF NOT EXISTS `cheque_books` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NULL,
  `bank_name` VARCHAR(255) NULL,
  `shop_account_id` BIGINT UNSIGNED NULL,
  `start_number` BIGINT UNSIGNED NOT NULL,
  `end_number` BIGINT UNSIGNED NOT NULL,
  `leaf_count` INT UNSIGNED NOT NULL,
  `note` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cheque_books_atelier_id_index` (`atelier_id`),
  KEY `cheque_books_shop_account_id_index` (`shop_account_id`),
  KEY `cheque_books_atelier_id_id_index` (`atelier_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cheque_book_leaves` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cheque_book_id` BIGINT UNSIGNED NOT NULL,
  `leaf_number` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'unused',
  `cheque_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cheque_book_leaves_book_leaf_unique` (`cheque_book_id`, `leaf_number`),
  KEY `cheque_book_leaves_cheque_book_id_index` (`cheque_book_id`),
  KEY `cheque_book_leaves_status_index` (`status`),
  KEY `cheque_book_leaves_cheque_id_index` (`cheque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `cheques`
  ADD COLUMN `cheque_book_leaf_id` BIGINT UNSIGNED NULL AFTER `cheque_number`;

ALTER TABLE `cheques`
  ADD INDEX `cheques_cheque_book_leaf_id_index` (`cheque_book_leaf_id`);
