-- ذینفع فاکتور/هزینه: کاربر باشگاه مشتریان که از او خرید شده
-- معادل migration 2026_08_29_100000
-- هر دستور را جدا اجرا کنید. اگر Duplicate column / Duplicate key آمد همان را رد کنید.

ALTER TABLE `invoices`
    ADD COLUMN `beneficiary_id` BIGINT UNSIGNED NULL AFTER `atelier_id`;

ALTER TABLE `invoices`
    ADD INDEX `invoices_beneficiary_id_index` (`beneficiary_id`);

ALTER TABLE `invoices`
    ADD CONSTRAINT `invoices_beneficiary_id_foreign`
        FOREIGN KEY (`beneficiary_id`) REFERENCES `user_shiksho` (`id`) ON DELETE SET NULL;

ALTER TABLE `expenses`
    ADD COLUMN `beneficiary_id` BIGINT UNSIGNED NULL AFTER `atelier_id`;

ALTER TABLE `expenses`
    ADD INDEX `expenses_beneficiary_id_index` (`beneficiary_id`);

ALTER TABLE `expenses`
    ADD CONSTRAINT `expenses_beneficiary_id_foreign`
        FOREIGN KEY (`beneficiary_id`) REFERENCES `user_shiksho` (`id`) ON DELETE SET NULL;
