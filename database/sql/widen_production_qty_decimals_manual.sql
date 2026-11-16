-- دقت اعشار برای پشتیبانی نیم‌گرم / ۲.۵ گرم در فرمول و مصرف FIFO
-- هر دستور را جدا اجرا کنید؛ اگر ستون از قبل DECIMAL(14,6) بود رد کنید.

ALTER TABLE `produced_good_ingredients`
  MODIFY COLUMN `grams_per_kg` DECIMAL(14,6) NOT NULL;

ALTER TABLE `raw_material_lots`
  MODIFY COLUMN `quantity_kg` DECIMAL(14,6) NOT NULL,
  MODIFY COLUMN `remaining_kg` DECIMAL(14,6) NOT NULL;

ALTER TABLE `productions`
  MODIFY COLUMN `quantity_kg` DECIMAL(14,6) NOT NULL,
  MODIFY COLUMN `remaining_kg` DECIMAL(14,6) NOT NULL DEFAULT 0;

ALTER TABLE `production_consumptions`
  MODIFY COLUMN `quantity_kg` DECIMAL(14,6) NOT NULL;

ALTER TABLE `purchase_stock_consumptions`
  MODIFY COLUMN `quantity_kg` DECIMAL(14,6) NOT NULL,
  MODIFY COLUMN `restored_kg` DECIMAL(14,6) NOT NULL DEFAULT 0;
