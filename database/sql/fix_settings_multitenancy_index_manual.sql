-- =============================================================================
-- اصلاح settings — فقط دستورات ساده (بدون information_schema، بدون PROCEDURE)
-- هر بلوک را به‌ترتیب اجرا کنید؛ اگر خطا «ستون تکراری» یا «ایندکس نیست» بود،
-- همان خط را رد کنید و بعدی را بزنید.
-- پیش‌نیاز: حداقل یک ردیف در ateliers | بکاپ بگیرید.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ۱) اگر گفت ستون هست، این را رد کنید
ALTER TABLE `settings` ADD COLUMN `atelier_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`;

-- ۲) پر کردن atelier_id
SET @default_aid := (SELECT MIN(id) FROM `ateliers`);

UPDATE `settings`
SET `atelier_id` = @default_aid
WHERE `atelier_id` IS NULL
  AND @default_aid IS NOT NULL;

-- ۳) حذف ردیف تکراری (در صورت وجود)
DELETE s1 FROM `settings` s1
INNER JOIN `settings` s2
  ON s1.`atelier_id` <=> s2.`atelier_id`
 AND s1.`key` = s2.`key`
 AND s1.`id` > s2.`id`;

-- ۴) ببینید نام ایندکس یکتای قدیمی روی key چیست (ستون Key_name)
SHOW INDEX FROM `settings`;

-- ۵) فقط «یکی» از این دو را اجرا کنید — نام درست را از خروجی مرحله ۴ بردارید
ALTER TABLE `settings` DROP INDEX `settings_key_unique`;
-- ALTER TABLE `settings` DROP INDEX `key`;

-- ۶) یکتای چند فروشگاهی
ALTER TABLE `settings`
  ADD UNIQUE KEY `settings_atelier_id_key_unique` (`atelier_id`, `key`);

-- ۷) اگر گفت FK از قبل هست، این را رد کنید
ALTER TABLE `settings`
  ADD CONSTRAINT `settings_atelier_id_foreign`
  FOREIGN KEY (`atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;

SHOW INDEX FROM `settings`;
