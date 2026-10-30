-- گسترش billiard_matches برای جدول دستی (حذفی / گروهی→حذفی)
-- اگر ستون از قبل هست، خط مربوطه را رد کنید.

ALTER TABLE `billiard_matches`
  ADD COLUMN `phase` varchar(20) NOT NULL DEFAULT 'knockout' AFTER `tournament_id`,
  ADD COLUMN `group_code` varchar(16) NOT NULL DEFAULT '' AFTER `phase`,
  ADD COLUMN `slot1_source_match_id` bigint unsigned NULL AFTER `next_slot`,
  ADD COLUMN `slot1_source_outcome` varchar(10) NULL AFTER `slot1_source_match_id`,
  ADD COLUMN `slot2_source_match_id` bigint unsigned NULL AFTER `slot1_source_outcome`,
  ADD COLUMN `slot2_source_outcome` varchar(10) NULL AFTER `slot2_source_match_id`;

-- unique قدیمی را بردارید (اگر نبود خطا می‌دهد؛ نادیده بگیرید)
ALTER TABLE `billiard_matches` DROP INDEX `billiard_matches_tournament_round_position_unique`;

ALTER TABLE `billiard_matches`
  ADD UNIQUE KEY `billiard_matches_phase_unique` (`tournament_id`, `phase`, `group_code`, `round`, `position`);
