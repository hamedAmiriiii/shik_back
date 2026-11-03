-- درخواست شرکت در مسابقات کشوری بیلیارد
-- معادل migration 2026_11_03_140000_create_billiard_national_entry_tables

CREATE TABLE IF NOT EXISTS `billiard_national_competitions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `discipline_id` bigint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billiard_national_competitions_is_active_sort_order_index` (`is_active`, `sort_order`),
  KEY `billiard_national_competitions_discipline_id_index` (`discipline_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `billiard_national_entry_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `player_id` bigint unsigned NOT NULL,
  `national_competition_id` bigint unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `discipline_id` bigint unsigned NOT NULL,
  `note` varchar(1000) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `admin_note` varchar(1000) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billiard_national_entry_requests_player_id_status_index` (`player_id`, `status`),
  KEY `billiard_national_entry_requests_status_created_at_index` (`status`, `created_at`),
  KEY `billiard_national_entry_requests_discipline_id_index` (`discipline_id`),
  KEY `billiard_national_entry_requests_national_competition_id_index` (`national_competition_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
