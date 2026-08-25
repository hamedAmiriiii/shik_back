-- فروش چکی: payment_type=cheque + لینک به چک دریافتی
ALTER TABLE `purchases`
  MODIFY `payment_type` ENUM('cash', 'installment', 'debt', 'cheque') NOT NULL DEFAULT 'cash';

ALTER TABLE `purchases`
  ADD COLUMN `cheque_id` bigint unsigned NULL AFTER `payment_type`,
  ADD INDEX `purchases_cheque_id_index` (`cheque_id`);

ALTER TABLE `cheques`
  ADD COLUMN `purchase_id` bigint unsigned NULL AFTER `atelier_id`,
  ADD UNIQUE KEY `cheques_purchase_id_unique` (`purchase_id`);

ALTER TABLE `purchases`
  ADD CONSTRAINT `purchases_cheque_id_foreign` FOREIGN KEY (`cheque_id`) REFERENCES `cheques` (`id`) ON DELETE SET NULL;

ALTER TABLE `cheques`
  ADD CONSTRAINT `cheques_purchase_id_foreign` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE SET NULL;
