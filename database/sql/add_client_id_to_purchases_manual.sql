-- شناسه یکتای کلاینت برای idempotency ثبت فاکتور (هر فروشگاه)
ALTER TABLE `purchases`
  ADD COLUMN `client_id` VARCHAR(64) NULL DEFAULT NULL AFTER `atelier_id`,
  ADD UNIQUE KEY `purchases_atelier_client_id_unique` (`atelier_id`, `client_id`);
