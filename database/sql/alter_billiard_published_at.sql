-- انتشار جدول و ساعت برای عموم. مسابقه‌های فعلی منتشر می‌مانند.
ALTER TABLE `billiard_tournaments`
  ADD COLUMN `published_at` timestamp NULL AFTER `drawn_at`;

UPDATE `billiard_tournaments` SET `published_at` = NOW() WHERE `published_at` IS NULL;
