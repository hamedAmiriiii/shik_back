-- =============================================================================
-- انتقال داده فروشگاه: webinop1_atelier (قدیم) → webinop1_webino (جدید)
--
-- قدیم: تک‌فروشگاه — ستون atelier_id روی جداول فروشگاه نبود (یا استفاده نمی‌شد).
-- جدید: چندفروشگاهی — همهٔ رکوردهای کپی‌شده از قدیم باید atelier_id = 5 بگیرند.
--
-- اجرا: phpMyAdmin روی webinop1_webino (یا هر DB؛ نام کامل جداول در query است).
-- قبل از اجرا: بکاپ webinop1_webino. migrate روی جدید زده شده باشد.
-- جداول مراسم (ceremonies, gardens, talars, ...) کپی نمی‌شوند.
-- جدول ateliers قدیم = آتلیه عروسی؛ فروشگاه مقصد فقط ردیف id=5 در webino است.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 0) فروشگاه در سیستم جدید — همهٔ دادهٔ قدیم به atelier_id = 5
-- (در قدیم atelier_id نداشتید؛ این فقط برای webino است.)
-- -----------------------------------------------------------------------------
SET @aid = 5;

SELECT @aid AS shop_atelier_id,
  (SELECT `name` FROM `webinop1_webino`.`ateliers` WHERE `id` = @aid) AS shop_name;
-- اگر shop_name خالی بود، قبل از ادامه در webino ردیف ateliers با id=5 بسازید.

-- -----------------------------------------------------------------------------
-- اختیاری: اگر webino از قبل داده تست دارد و می‌خواهید فقط داده قدیم بیاید،
-- بلوک TRUNCATE زیر را یک‌بار باز کنید (ترتیب وابستگی رعایت شده).
-- -----------------------------------------------------------------------------
/*
USE webinop1_webino;
TRUNCATE TABLE cart_items;
TRUNCATE TABLE installments;
TRUNCATE TABLE purchased_products;
TRUNCATE TABLE purchases;
TRUNCATE TABLE returned_products;
TRUNCATE TABLE carts;
TRUNCATE TABLE category_product;
TRUNCATE TABLE product_images;
TRUNCATE TABLE products;
TRUNCATE TABLE categories;
TRUNCATE TABLE manufacturers;
TRUNCATE TABLE customer_addresses;
TRUNCATE TABLE customers;
TRUNCATE TABLE customer_phones;
TRUNCATE TABLE user_shiksho;
TRUNCATE TABLE invoices;
TRUNCATE TABLE expenses;
TRUNCATE TABLE shop_sms_logs;
TRUNCATE TABLE confirmation_codes;
DELETE FROM settings WHERE atelier_id = @aid OR atelier_id IS NULL;
*/

-- -----------------------------------------------------------------------------
-- 1) پایه — همان id قدیم حفظ می‌شود (برای FKهای purchased_products و ...)
-- -----------------------------------------------------------------------------

INSERT INTO `webinop1_webino`.`manufacturers` (`id`, `name`, `atelier_id`, `created_at`, `updated_at`)
SELECT `id`, `name`, @aid, `created_at`, `updated_at`
FROM `webinop1_atelier`.`manufacturers` AS s
ON DUPLICATE KEY UPDATE `name` = s.`name`, `atelier_id` = @aid;

INSERT INTO `webinop1_webino`.`categories` (`id`, `name`, `slug`, `description`, `parent_id`, `order`, `is_active`, `atelier_id`, `created_at`, `updated_at`)
SELECT `id`, `name`, `slug`, `description`, `parent_id`, `order`, `is_active`, @aid, `created_at`, `updated_at`
FROM `webinop1_atelier`.`categories` AS s
ON DUPLICATE KEY UPDATE `name` = s.`name`, `atelier_id` = @aid;

-- خطای 1062 (5-1505): در webino قبلاً محصولی با همان بارکد ولی id دیگر برای این فروشگاه هست.
-- ردیف‌های متعارض webino حذف می‌شوند تا insert با id قدیم درست شود.
DELETE p FROM `webinop1_webino`.`products` p
INNER JOIN `webinop1_atelier`.`products` o
  ON o.`barcode` = p.`barcode`
  AND o.`barcode` IS NOT NULL
  AND o.`barcode` <> ''
WHERE p.`atelier_id` = @aid
  AND p.`id` <> o.`id`;

INSERT INTO `webinop1_webino`.`products` (
  `id`, `name`, `purchase_price`, `sale_price`, `quantity`, `barcode`,
  `manufacturer_id`, `original_sale_price`, `sizes`, `colors`,
  `atelier_id`, `created_at`, `updated_at`, `deleted_at`
)
SELECT
  `id`, `name`, `purchase_price`, `sale_price`, `quantity`, `barcode`,
  `manufacturer_id`, `original_sale_price`, `sizes`, `colors`,
  @aid, `created_at`, `updated_at`, NULL
FROM `webinop1_atelier`.`products` AS s
ON DUPLICATE KEY UPDATE
  `name` = s.`name`,
  `purchase_price` = s.`purchase_price`,
  `sale_price` = s.`sale_price`,
  `quantity` = s.`quantity`,
  `barcode` = s.`barcode`,
  `manufacturer_id` = s.`manufacturer_id`,
  `original_sale_price` = s.`original_sale_price`,
  `sizes` = s.`sizes`,
  `colors` = s.`colors`,
  `atelier_id` = @aid,
  `updated_at` = s.`updated_at`,
  `deleted_at` = NULL;

INSERT INTO `webinop1_webino`.`product_images` (`id`, `product_id`, `image_path`, `order`, `created_at`, `updated_at`)
SELECT `id`, `product_id`, `image_path`, `order`, `created_at`, `updated_at`
FROM `webinop1_atelier`.`product_images` AS s
ON DUPLICATE KEY UPDATE `image_path` = s.`image_path`;

INSERT IGNORE INTO `webinop1_webino`.`category_product` (`category_id`, `product_id`)
SELECT `category_id`, `product_id`
FROM `webinop1_atelier`.`category_product`;

-- -----------------------------------------------------------------------------
-- 2) مشتریان و باشگاه
-- -----------------------------------------------------------------------------

INSERT INTO `webinop1_webino`.`customers` (
  `id`, `phone`, `password`, `name`, `last_name`, `national_code`,
  `state_id`, `city_id`, `address`, `postal_code`, `is_verified`,
  `atelier_id`, `created_at`, `updated_at`
)
SELECT
  `id`, `phone`, `password`, `name`, `last_name`, `national_code`,
  `state_id`, `city_id`, `address`, `postal_code`, `is_verified`,
  @aid, `created_at`, `updated_at`
FROM `webinop1_atelier`.`customers` AS s
ON DUPLICATE KEY UPDATE `phone` = s.`phone`, `atelier_id` = @aid;

INSERT INTO `webinop1_webino`.`customer_phones` (`id`, `phone`, `created_at`, `updated_at`)
SELECT `id`, `phone`, `created_at`, `updated_at`
FROM `webinop1_atelier`.`customer_phones` AS s
ON DUPLICATE KEY UPDATE `phone` = s.`phone`;

INSERT INTO `webinop1_webino`.`customer_addresses` (
  `id`, `customer_id`, `title`, `name`, `last_name`, `phone`, `address`,
  `state_id`, `state_name`, `city_id`, `city_name`, `postal_code`, `is_default`,
  `created_at`, `updated_at`
)
SELECT
  `id`, `customer_id`, `title`, `name`, `last_name`, `phone`, `address`,
  `state_id`, `state_name`, `city_id`, `city_name`, `postal_code`, `is_default`,
  `created_at`, `updated_at`
FROM `webinop1_atelier`.`customer_addresses` AS s
ON DUPLICATE KEY UPDATE `address` = s.`address`;

INSERT INTO `webinop1_webino`.`user_shiksho` (
  `id`, `phone`, `credit`, `credit_last_updated_at`, `last_warning_sent_at`,
  `atelier_id`, `created_at`, `updated_at`
)
SELECT
  `id`, `phone`, `credit`, `credit_last_updated_at`, `last_warning_sent_at`,
  @aid, `created_at`, `updated_at`
FROM `webinop1_atelier`.`user_shiksho` AS s
ON DUPLICATE KEY UPDATE `credit` = s.`credit`, `atelier_id` = @aid;

-- -----------------------------------------------------------------------------
-- 3) فروش و سبد
-- -----------------------------------------------------------------------------

INSERT INTO `webinop1_webino`.`carts` (
  `id`, `customer_id`, `address_id`, `status`, `atelier_id`,
  `shipping_name`, `shipping_last_name`, `shipping_phone`, `shipping_address`,
  `shipping_state_id`, `shipping_state_name`, `shipping_city_id`, `shipping_city_name`, `shipping_postal_code`,
  `created_at`, `updated_at`
)
SELECT
  `id`, `customer_id`, `address_id`, `status`, @aid,
  `shipping_name`, `shipping_last_name`, `shipping_phone`, `shipping_address`,
  `shipping_state_id`, `shipping_state_name`, `shipping_city_id`, `shipping_city_name`, `shipping_postal_code`,
  `created_at`, `updated_at`
FROM `webinop1_atelier`.`carts` AS s
ON DUPLICATE KEY UPDATE `atelier_id` = @aid;

-- قدیم معمولاً card_amount / cash_amount / payment_type ندارد — مقدار پیش‌فرض می‌گذاریم.
-- اگر در قدیم cart_id یا installment_* دارید، بلوک جایگزین پایین فایل را ببینید.
INSERT INTO `webinop1_webino`.`purchases` (
  `id`, `cart_id`, `phone`, `total_amount`, `credit_used`, `credit_earned`,
  `payment_type`, `card_amount`, `cash_amount`, `installment_count`, `installment_amount`,
  `atelier_id`, `created_at`, `updated_at`
)
SELECT
  s.`id`,
  NULL AS `cart_id`,
  s.`phone`,
  s.`total_amount`,
  s.`credit_used`,
  s.`credit_earned`,
  'cash' AS `payment_type`,
  0 AS `card_amount`,
  0 AS `cash_amount`,
  NULL AS `installment_count`,
  NULL AS `installment_amount`,
  @aid,
  s.`created_at`,
  s.`updated_at`
FROM `webinop1_atelier`.`purchases` AS s
ON DUPLICATE KEY UPDATE
  `phone` = s.`phone`,
  `total_amount` = s.`total_amount`,
  `credit_used` = s.`credit_used`,
  `credit_earned` = s.`credit_earned`,
  `atelier_id` = @aid;

-- خریدهایی که در webino قسط دارند → payment_type = installment
UPDATE `webinop1_webino`.`purchases` p
SET p.`payment_type` = 'installment'
WHERE p.`atelier_id` = @aid
  AND EXISTS (
    SELECT 1 FROM `webinop1_webino`.`installments` i WHERE i.`purchase_id` = p.`id`
  );

INSERT INTO `webinop1_webino`.`purchased_products` (
  `id`, `product_id`, `purchase_id`, `quantity`, `purchase_price`, `sale_price`,
  `phone`, `total_amount`, `credit_used`, `credit_earned`, `size`, `color`,
  `created_at`, `updated_at`
)
SELECT
  `id`, `product_id`, `purchase_id`, `quantity`, `purchase_price`, `sale_price`,
  `phone`, `total_amount`, `credit_used`, `credit_earned`, `size`, `color`,
  `created_at`, `updated_at`
FROM `webinop1_atelier`.`purchased_products` AS s
ON DUPLICATE KEY UPDATE `quantity` = s.`quantity`;

INSERT INTO `webinop1_webino`.`cart_items` (`id`, `cart_id`, `product_id`, `quantity`, `size`, `color`, `created_at`, `updated_at`)
SELECT `id`, `cart_id`, `product_id`, `quantity`, `size`, `color`, `created_at`, `updated_at`
FROM `webinop1_atelier`.`cart_items` AS s
ON DUPLICATE KEY UPDATE `quantity` = s.`quantity`;

INSERT INTO `webinop1_webino`.`installments` (
  `id`, `purchase_id`, `installment_number`, `amount`, `due_date`, `is_paid`, `paid_at`, `notes`, `created_at`, `updated_at`
)
SELECT `id`, `purchase_id`, `installment_number`, `amount`, `due_date`, `is_paid`, `paid_at`, `notes`, `created_at`, `updated_at`
FROM `webinop1_atelier`.`installments` AS s
ON DUPLICATE KEY UPDATE `is_paid` = s.`is_paid`;

-- -----------------------------------------------------------------------------
-- 4) مالی و برگشت
-- -----------------------------------------------------------------------------

INSERT INTO `webinop1_webino`.`invoices` (`id`, `amount`, `title`, `description`, `date`, `user_name`, `atelier_id`, `created_at`, `updated_at`)
SELECT `id`, `amount`, `title`, `description`, `date`, `user_name`, @aid, `created_at`, `updated_at`
FROM `webinop1_atelier`.`invoices` AS s
ON DUPLICATE KEY UPDATE `atelier_id` = @aid;

INSERT INTO `webinop1_webino`.`expenses` (`id`, `user_name`, `date`, `amount`, `title`, `type`, `atelier_id`, `created_at`, `updated_at`)
SELECT `id`, `user_name`, `date`, `amount`, `title`, `type`, @aid, `created_at`, `updated_at`
FROM `webinop1_atelier`.`expenses` AS s
ON DUPLICATE KEY UPDATE `atelier_id` = @aid;

INSERT INTO `webinop1_webino`.`returned_products` (`id`, `product_id`, `sale_price`, `atelier_id`, `created_at`, `updated_at`)
SELECT `id`, `product_id`, `sale_price`, @aid, `created_at`, `updated_at`
FROM `webinop1_atelier`.`returned_products` AS s
ON DUPLICATE KEY UPDATE `atelier_id` = @aid;

INSERT INTO `webinop1_webino`.`shop_sms_logs` (
  `id`, `phone`, `message`, `purchase_id`, `credit_amount`, `sms_type`, `atelier_id`, `created_at`, `updated_at`
)
SELECT `id`, `phone`, `message`, `purchase_id`, `credit_amount`, `sms_type`, @aid, `created_at`, `updated_at`
FROM `webinop1_atelier`.`shop_sms_logs` AS s
ON DUPLICATE KEY UPDATE `atelier_id` = @aid;

INSERT INTO `webinop1_webino`.`confirmation_codes` (`id`, `atelier_id`, `phone`, `code`, `created_at`, `updated_at`)
SELECT `id`, @aid, `phone`, `code`, `created_at`, `updated_at`
FROM `webinop1_atelier`.`confirmation_codes` AS s
ON DUPLICATE KEY UPDATE `code` = s.`code`;

-- settings: در webino ممکن است همان key دو بار باشد (atelier_id=NULL و atelier_id=5).
-- UPDATE هر دو به 5 → خطای 1062. اول ردیف‌های متعارض را حذف، بعد از قدیم insert.
DELETE w FROM `webinop1_webino`.`settings` w
INNER JOIN `webinop1_atelier`.`settings` o ON o.`key` = w.`key`
WHERE w.`atelier_id` = @aid OR w.`atelier_id` IS NULL;

INSERT INTO `webinop1_webino`.`settings` (`key`, `value`, `atelier_id`, `created_at`, `updated_at`)
SELECT o.`key`, o.`value`, @aid, o.`created_at`, o.`updated_at`
FROM `webinop1_atelier`.`settings` o;

-- -----------------------------------------------------------------------------
-- 5) پرسنل فروشگاه — فقط اگر در قدیم atelier_id خالی بود (کاربر مراسم عوض نشود)
-- برای ادمین فروشگاه: شماره را در IN (...) بگذارید یا خط بعدی را باز کنید.
-- -----------------------------------------------------------------------------
UPDATE `webinop1_webino`.`users` u
INNER JOIN `webinop1_atelier`.`users` o ON o.`id` = u.`id`
SET u.`atelier_id` = @aid
WHERE o.`atelier_id` IS NULL;

-- UPDATE `webinop1_webino`.`users` SET `atelier_id` = @aid, `shop_staff_role` = 'owner' WHERE `phone` = '09XXXXXXXXX';

-- -----------------------------------------------------------------------------
-- 5b) اگر قبلاً import زده بودید — اصلاح atelier_id برای رکوردهای کپی‌شده
-- -----------------------------------------------------------------------------
UPDATE `webinop1_webino`.`manufacturers` m
INNER JOIN `webinop1_atelier`.`manufacturers` o ON o.`id` = m.`id`
SET m.`atelier_id` = @aid;

UPDATE `webinop1_webino`.`categories` t
INNER JOIN `webinop1_atelier`.`categories` o ON o.`id` = t.`id`
SET t.`atelier_id` = @aid;

UPDATE `webinop1_webino`.`products` t
INNER JOIN `webinop1_atelier`.`products` o ON o.`id` = t.`id`
SET t.`atelier_id` = @aid;

UPDATE `webinop1_webino`.`customers` t
INNER JOIN `webinop1_atelier`.`customers` o ON o.`id` = t.`id`
SET t.`atelier_id` = @aid;

UPDATE `webinop1_webino`.`user_shiksho` t
INNER JOIN `webinop1_atelier`.`user_shiksho` o ON o.`id` = t.`id`
SET t.`atelier_id` = @aid;

UPDATE `webinop1_webino`.`purchases` t
INNER JOIN `webinop1_atelier`.`purchases` o ON o.`id` = t.`id`
SET t.`atelier_id` = @aid;

UPDATE `webinop1_webino`.`carts` t
INNER JOIN `webinop1_atelier`.`carts` o ON o.`id` = t.`id`
SET t.`atelier_id` = @aid;

UPDATE `webinop1_webino`.`invoices` t
INNER JOIN `webinop1_atelier`.`invoices` o ON o.`id` = t.`id`
SET t.`atelier_id` = @aid;

UPDATE `webinop1_webino`.`expenses` t
INNER JOIN `webinop1_atelier`.`expenses` o ON o.`id` = t.`id`
SET t.`atelier_id` = @aid;

UPDATE `webinop1_webino`.`returned_products` t
INNER JOIN `webinop1_atelier`.`returned_products` o ON o.`id` = t.`id`
SET t.`atelier_id` = @aid;

UPDATE `webinop1_webino`.`shop_sms_logs` t
INNER JOIN `webinop1_atelier`.`shop_sms_logs` o ON o.`id` = t.`id`
SET t.`atelier_id` = @aid;

UPDATE `webinop1_webino`.`confirmation_codes` t
INNER JOIN `webinop1_atelier`.`confirmation_codes` o ON o.`id` = t.`id`
SET t.`atelier_id` = @aid;

-- -----------------------------------------------------------------------------
-- 5c) اطمینان نهایی: هر رکوردی که idاش از قدیم است → atelier_id = 5
-- (قدیم atelier_id نداشت؛ اگر جایی NULL مانده باشد این بخش درست می‌کند.)
-- -----------------------------------------------------------------------------
UPDATE `webinop1_webino`.`manufacturers` SET `atelier_id` = @aid WHERE `id` IN (SELECT `id` FROM `webinop1_atelier`.`manufacturers`);
UPDATE `webinop1_webino`.`categories` SET `atelier_id` = @aid WHERE `id` IN (SELECT `id` FROM `webinop1_atelier`.`categories`);
UPDATE `webinop1_webino`.`products` SET `atelier_id` = @aid WHERE `id` IN (SELECT `id` FROM `webinop1_atelier`.`products`);
UPDATE `webinop1_webino`.`customers` SET `atelier_id` = @aid WHERE `id` IN (SELECT `id` FROM `webinop1_atelier`.`customers`);
UPDATE `webinop1_webino`.`user_shiksho` SET `atelier_id` = @aid WHERE `id` IN (SELECT `id` FROM `webinop1_atelier`.`user_shiksho`);
UPDATE `webinop1_webino`.`carts` SET `atelier_id` = @aid WHERE `id` IN (SELECT `id` FROM `webinop1_atelier`.`carts`);
UPDATE `webinop1_webino`.`purchases` SET `atelier_id` = @aid WHERE `id` IN (SELECT `id` FROM `webinop1_atelier`.`purchases`);
UPDATE `webinop1_webino`.`invoices` SET `atelier_id` = @aid WHERE `id` IN (SELECT `id` FROM `webinop1_atelier`.`invoices`);
UPDATE `webinop1_webino`.`expenses` SET `atelier_id` = @aid WHERE `id` IN (SELECT `id` FROM `webinop1_atelier`.`expenses`);
UPDATE `webinop1_webino`.`returned_products` SET `atelier_id` = @aid WHERE `id` IN (SELECT `id` FROM `webinop1_atelier`.`returned_products`);
UPDATE `webinop1_webino`.`shop_sms_logs` SET `atelier_id` = @aid WHERE `id` IN (SELECT `id` FROM `webinop1_atelier`.`shop_sms_logs`);
UPDATE `webinop1_webino`.`confirmation_codes` SET `atelier_id` = @aid WHERE `id` IN (SELECT `id` FROM `webinop1_atelier`.`confirmation_codes`);

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- 6) کنترل
-- -----------------------------------------------------------------------------
SELECT 'products' AS tbl,
  (SELECT COUNT(*) FROM webinop1_atelier.products) AS old_cnt,
  (SELECT COUNT(*) FROM webinop1_webino.products WHERE atelier_id = @aid) AS new_cnt
UNION ALL
SELECT 'purchases',
  (SELECT COUNT(*) FROM webinop1_atelier.purchases),
  (SELECT COUNT(*) FROM webinop1_webino.purchases WHERE atelier_id = @aid)
UNION ALL
SELECT 'customers',
  (SELECT COUNT(*) FROM webinop1_atelier.customers),
  (SELECT COUNT(*) FROM webinop1_webino.customers WHERE atelier_id = @aid);

SELECT @aid AS shop_atelier_id;

-- رکوردهای import‌شده که هنوز atelier_id شان 5 نیست (باید 0 باشد):
SELECT 'products_wrong_atelier' AS chk, COUNT(*) AS cnt
FROM `webinop1_webino`.`products` p
WHERE p.`id` IN (SELECT `id` FROM `webinop1_atelier`.`products`)
  AND (p.`atelier_id` IS NULL OR p.`atelier_id` <> @aid);
