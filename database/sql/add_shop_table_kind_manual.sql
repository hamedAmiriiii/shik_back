-- نوع میز/اتاق روی shop_tables
-- میز و اتاق می‌توانند شماره یکسان داشته باشند.

ALTER TABLE `shop_tables`
    ADD COLUMN `kind` VARCHAR(16) NOT NULL DEFAULT 'table' AFTER `table_number`;

UPDATE `shop_tables` SET `kind` = 'table' WHERE `kind` IS NULL OR `kind` = '';

ALTER TABLE `shop_tables` DROP INDEX `shop_tables_unique`;

ALTER TABLE `shop_tables`
    ADD UNIQUE KEY `shop_tables_unique` (`atelier_id`, `kind`, `table_number`);
