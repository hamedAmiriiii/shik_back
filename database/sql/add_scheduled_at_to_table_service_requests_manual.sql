-- زمان‌بندی خدمات اتاق
ALTER TABLE `table_service_requests`
    ADD COLUMN `scheduled_at` TIMESTAMP NULL AFTER `status`;
