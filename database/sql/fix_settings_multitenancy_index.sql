-- =============================================================================
-- اصلاح جدول settings برای چند فروشگاهی — بدون information_schema
--
-- اگر خطای #1044 روی information_schema می‌گیرید، این فایل مناسب است.
-- راه بدون PROCEDURE: `fix_settings_multitenancy_index_manual.sql`
--
-- این نسخه فقط از جداول خودتان و (اختیاری) یک PROCEDURE استفاده می‌کند.
--
-- پیش‌نیاز: حداقل یک ردیف در `ateliers` | از دیتابیس پروژه بکاپ بگیرید.
-- در phpMyAdmin همان دیتابیس را از منوی چپ انتخاب کنید.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- روش ۱ (ترجیحی): یک‌جا اجرا کنید. خطاهای «ستون هست / ایندکس نیست / …» نادیده
-- گرفته می‌شود. اگر هاست اجازهٔ CREATE PROCEDURE نداد، بروید «روش ۲» پایین.
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `fix_settings_multitenancy_idx`;

DELIMITER $$

CREATE PROCEDURE `fix_settings_multitenancy_idx`()
SQL SECURITY INVOKER
COMMENT 'Fix settings unique for multitenancy without information_schema'
BEGIN
  DECLARE CONTINUE HANDLER FOR 1060, 1091, 1061, 1826 BEGIN
    SET @__fix_settings_skip := IFNULL(@__fix_settings_skip, 0) + 1;
  END;

  SET SESSION foreign_key_checks = 0;

  ALTER TABLE `settings`
    ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;

  SET @default_aid := (SELECT MIN(id) FROM `ateliers`);

  UPDATE `settings`
  SET `atelier_id` = @default_aid
  WHERE `atelier_id` IS NULL
    AND @default_aid IS NOT NULL;

  DELETE s1 FROM `settings` s1
  INNER JOIN `settings` s2
    ON s1.`atelier_id` <=> s2.`atelier_id`
   AND s1.`key` = s2.`key`
   AND s1.`id` > s2.`id`;

  /* نام‌های رایج برای UNIQUE فقط روی ستون key — هر کدام که نباشد نادیده */
  ALTER TABLE `settings` DROP INDEX `settings_key_unique`;
  ALTER TABLE `settings` DROP INDEX `key`;

  ALTER TABLE `settings`
    ADD UNIQUE KEY `settings_atelier_id_key_unique` (`atelier_id`, `key`);

  ALTER TABLE `settings`
    ADD CONSTRAINT `settings_atelier_id_foreign`
    FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

  SET SESSION foreign_key_checks = 1;
END$$

DELIMITER ;

CALL `fix_settings_multitenancy_idx`();

DROP PROCEDURE IF EXISTS `fix_settings_multitenancy_idx`;

-- گزارش (SHOW به information_schema دسترسی نمی‌خواهد؛ روی جدول شماست)
SHOW INDEX FROM `settings`;

-- =============================================================================
-- روش ۲ — اگر CREATE PROCEDURE ممکن نیست: هر بلوک را جدا اجرا کنید.
-- اگر یک خطا داد «Duplicate column» یا «Can't DROP INDEX» همان خط را رد کنید
-- و بعدی را بزنید.
-- =============================================================================
/*
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `settings` ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;

SET @default_aid := (SELECT MIN(id) FROM `ateliers`);
UPDATE `settings` SET `atelier_id` = @default_aid WHERE `atelier_id` IS NULL AND @default_aid IS NOT NULL;

DELETE s1 FROM `settings` s1
INNER JOIN `settings` s2
  ON s1.`atelier_id` <=> s2.`atelier_id` AND s1.`key` = s2.`key` AND s1.`id` > s2.`id`;

-- قبل از DROP حتماً بزنید و ستون Key_name را ببینید:
SHOW INDEX FROM `settings`;

ALTER TABLE `settings` DROP INDEX `settings_key_unique`;
-- اگر خطا: امتحان کنید:
-- ALTER TABLE `settings` DROP INDEX `key`;

ALTER TABLE `settings` ADD UNIQUE KEY `settings_atelier_id_key_unique` (`atelier_id`, `key`);

ALTER TABLE `settings` ADD CONSTRAINT `settings_atelier_id_foreign`
  FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;
SHOW INDEX FROM `settings`;
*/
