-- شماره فیش روزانه هر فروشگاه (از ۱؛ هر روز از نو)
ALTER TABLE `purchases`
  ADD COLUMN `daily_ticket_number` INT UNSIGNED NULL DEFAULT NULL AFTER `atelier_id`,
  ADD COLUMN `daily_ticket_date` DATE NULL DEFAULT NULL AFTER `daily_ticket_number`,
  ADD INDEX `purchases_atelier_daily_ticket_index` (`atelier_id`, `daily_ticket_date`, `daily_ticket_number`);

CREATE TABLE IF NOT EXISTS `shop_daily_ticket_counters` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `atelier_id` BIGINT UNSIGNED NOT NULL,
  `ticket_date` DATE NOT NULL,
  `last_number` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_daily_ticket_counters_unique` (`atelier_id`, `ticket_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
