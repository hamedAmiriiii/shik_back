-- شماره فاکتور جدا برای هر فروشگاه، از ۱
ALTER TABLE `invoices`
  ADD COLUMN `number` INT UNSIGNED NULL AFTER `id`;

UPDATE `invoices` AS i
JOIN (
  SELECT `id`, ROW_NUMBER() OVER (PARTITION BY `atelier_id` ORDER BY `id`) AS `num`
  FROM `invoices`
) AS ranked ON ranked.id = i.id
SET i.`number` = ranked.num;

ALTER TABLE `invoices`
  ADD UNIQUE KEY `invoices_atelier_number_unique` (`atelier_id`, `number`);
