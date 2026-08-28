-- نام مشتری در باشگاه مشتریان فروشگاه
-- معادل migration 2026_08_28_140000

ALTER TABLE `user_shiksho`
    ADD COLUMN `name` VARCHAR(255) NULL AFTER `phone`;
