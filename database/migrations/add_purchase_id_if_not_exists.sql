-- SQL برای اضافه کردن purchase_id به جدول purchased_products
-- این SQL ابتدا بررسی می‌کند که آیا فیلد وجود دارد یا نه

-- اگر فیلد purchase_id وجود ندارد، آن را اضافه می‌کند
SET @dbname = DATABASE();
SET @tablename = 'purchased_products';
SET @columnname = 'purchase_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column purchase_id already exists in purchased_products" AS result;',
  CONCAT('ALTER TABLE `', @tablename, '` 
    ADD COLUMN `', @columnname, '` BIGINT UNSIGNED NULL AFTER `id`,
    ADD CONSTRAINT `purchased_products_purchase_id_foreign` 
      FOREIGN KEY (`', @columnname, '`) REFERENCES `purchases` (`id`) 
      ON DELETE CASCADE;')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

