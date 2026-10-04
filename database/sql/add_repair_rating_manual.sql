-- امتیاز و نظر مشتری برای تعمیرکار (معادل migration 2026_10_04_100000_add_rating_to_repair_requests)
ALTER TABLE `repair_requests`
  ADD COLUMN `rating` tinyint unsigned DEFAULT NULL AFTER `cancel_reason`,
  ADD COLUMN `review` text DEFAULT NULL AFTER `rating`,
  ADD COLUMN `rated_at` timestamp NULL DEFAULT NULL AFTER `review`;

ALTER TABLE `repair_users`
  ADD COLUMN `rating_avg` decimal(3,2) DEFAULT NULL AFTER `labor_share_percent`,
  ADD COLUMN `rating_count` int unsigned NOT NULL DEFAULT 0 AFTER `rating_avg`;
