-- سامانه مسابقات بیلیارد — جداول billiard_*
-- معادل migration: 2026_10_29_140000_create_billiard_tables.php
-- اجرا: mysql ... < create_billiard_tables_manual.sql

CREATE TABLE IF NOT EXISTS `billiard_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `role` varchar(20) NOT NULL DEFAULT 'player' COMMENT 'admin|operator|player',
  `first_name` varchar(255) NULL,
  `last_name` varchar(255) NULL,
  `phone` varchar(20) NOT NULL,
  `national_id` varchar(20) NULL,
  `birth_date` date NULL,
  `photo_path` varchar(255) NULL,
  `city` varchar(255) NULL,
  `club` varchar(255) NULL,
  `membership_at` timestamp NULL,
  `membership_status` varchar(30) NOT NULL DEFAULT 'active',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text NULL,
  `last_login_at` timestamp NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billiard_users_phone_unique` (`phone`),
  UNIQUE KEY `billiard_users_national_id_unique` (`national_id`),
  KEY `billiard_users_role_is_active_index` (`role`, `is_active`),
  KEY `billiard_users_last_name_first_name_index` (`last_name`, `first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `billiard_disciplines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billiard_disciplines_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `billiard_tournaments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `poster_path` varchar(255) NULL,
  `discipline_id` bigint unsigned NOT NULL,
  `format` varchar(40) NOT NULL DEFAULT 'single_elimination',
  `venue` varchar(255) NULL,
  `host_club` varchar(255) NULL,
  `starts_on` date NULL,
  `ends_on` date NULL,
  `starts_at_time` time NULL,
  `capacity` int unsigned NULL,
  `fee_toman` bigint unsigned NOT NULL DEFAULT 0,
  `registration_deadline` timestamp NULL,
  `description` text NULL,
  `rules` text NULL,
  `status` varchar(40) NOT NULL DEFAULT 'pre_registration',
  `drawn_at` timestamp NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billiard_tournaments_slug_unique` (`slug`),
  KEY `billiard_tournaments_status_starts_on_index` (`status`, `starts_on`),
  KEY `billiard_tournaments_discipline_id_index` (`discipline_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `billiard_registrations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tournament_id` bigint unsigned NOT NULL,
  `player_id` bigint unsigned NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending' COMMENT 'pending|confirmed|rejected|cancelled',
  `payment_status` varchar(30) NOT NULL DEFAULT 'unpaid' COMMENT 'unpaid|pending|paid|waived|failed',
  `fee_toman` bigint unsigned NOT NULL DEFAULT 0,
  `gateway_payment_id` bigint unsigned NULL,
  `payment_ref` varchar(255) NULL,
  `seed` int unsigned NULL,
  `bracket_position` int unsigned NULL,
  `final_place` int unsigned NULL,
  `points_awarded` int unsigned NOT NULL DEFAULT 0,
  `confirmed_at` timestamp NULL,
  `paid_at` timestamp NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billiard_registrations_tournament_player_unique` (`tournament_id`, `player_id`),
  KEY `billiard_registrations_tournament_status_index` (`tournament_id`, `status`),
  KEY `billiard_registrations_player_status_index` (`player_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `billiard_tables` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tournament_id` bigint unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  KEY `billiard_tables_tournament_sort_index` (`tournament_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `billiard_matches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tournament_id` bigint unsigned NOT NULL,
  `round` int unsigned NOT NULL DEFAULT 1,
  `position` int unsigned NOT NULL DEFAULT 1,
  `round_label` varchar(255) NULL,
  `player1_id` bigint unsigned NULL,
  `player2_id` bigint unsigned NULL,
  `winner_id` bigint unsigned NULL,
  `score1` int unsigned NULL,
  `score2` int unsigned NULL,
  `table_id` bigint unsigned NULL,
  `scheduled_on` date NULL,
  `scheduled_at` time NULL,
  `status` varchar(30) NOT NULL DEFAULT 'scheduled' COMMENT 'scheduled|live|finished|cancelled|walkover',
  `next_match_id` bigint unsigned NULL,
  `next_slot` tinyint unsigned NULL COMMENT '1 or 2',
  `referee_id` bigint unsigned NULL,
  `notes` text NULL,
  `started_at` timestamp NULL,
  `finished_at` timestamp NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billiard_matches_tournament_round_position_unique` (`tournament_id`, `round`, `position`),
  KEY `billiard_matches_tournament_status_index` (`tournament_id`, `status`),
  KEY `billiard_matches_scheduled_index` (`scheduled_on`, `scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `billiard_ranking_place_points` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tournament_id` bigint unsigned NOT NULL,
  `place` int unsigned NOT NULL,
  `points` int unsigned NOT NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billiard_ranking_place_points_tournament_place_unique` (`tournament_id`, `place`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `billiard_ranking_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` text NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billiard_ranking_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `billiard_ranking_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `discipline_id` bigint unsigned NOT NULL,
  `player_id` bigint unsigned NOT NULL,
  `points` int unsigned NOT NULL DEFAULT 0,
  `rank` int unsigned NOT NULL DEFAULT 0,
  `tournaments_count` int unsigned NOT NULL DEFAULT 0,
  `wins` int unsigned NOT NULL DEFAULT 0,
  `losses` int unsigned NOT NULL DEFAULT 0,
  `titles` int unsigned NOT NULL DEFAULT 0,
  `runner_ups` int unsigned NOT NULL DEFAULT 0,
  `thirds` int unsigned NOT NULL DEFAULT 0,
  `computed_at` timestamp NULL,
  `source_tournament_id` bigint unsigned NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billiard_ranking_entries_discipline_player_unique` (`discipline_id`, `player_id`),
  KEY `billiard_ranking_entries_discipline_rank_index` (`discipline_id`, `rank`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `billiard_news` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `cover_path` varchar(255) NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `published_at` timestamp NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billiard_news_slug_unique` (`slug`),
  KEY `billiard_news_published_index` (`is_published`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- اختیاری: مقدار پیش‌فرض تنظیم رنکینگ
INSERT INTO `billiard_ranking_settings` (`key`, `value`, `created_at`, `updated_at`)
SELECT 'last_tournaments', '5', NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `billiard_ranking_settings` WHERE `key` = 'last_tournaments'
);
