-- Manual SQL for shop referral system (run if php artisan migrate is not available)

ALTER TABLE `users`
    ADD COLUMN `referral_code` VARCHAR(16) NULL UNIQUE AFTER `shop_staff_role`,
    ADD COLUMN `referral_dashboard_token` VARCHAR(64) NULL UNIQUE AFTER `referral_code`,
    ADD COLUMN `referral_balance` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `referral_dashboard_token`;

ALTER TABLE `ateliers`
    ADD COLUMN `subscription_status` VARCHAR(16) NOT NULL DEFAULT 'trial' AFTER `shop_access_suspended`,
    ADD COLUMN `referred_by_user_id` BIGINT UNSIGNED NULL AFTER `subscription_status`,
    ADD COLUMN `paid_plan_activated_at` TIMESTAMP NULL AFTER `referred_by_user_id`,
    ADD INDEX `ateliers_subscription_status_index` (`subscription_status`),
    ADD CONSTRAINT `ateliers_referred_by_user_id_foreign`
        FOREIGN KEY (`referred_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

CREATE TABLE `shop_referrals` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `referrer_user_id` BIGINT UNSIGNED NOT NULL,
    `referred_user_id` BIGINT UNSIGNED NOT NULL,
    `referred_atelier_id` BIGINT UNSIGNED NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'registered',
    `reward_amount` DECIMAL(15,2) NULL,
    `registered_at` TIMESTAMP NULL,
    `plan_activated_at` TIMESTAMP NULL,
    `rewarded_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    UNIQUE KEY `shop_referrals_referred_atelier_id_unique` (`referred_atelier_id`),
    KEY `shop_referrals_referrer_user_id_status_index` (`referrer_user_id`, `status`),
    CONSTRAINT `shop_referrals_referrer_user_id_foreign`
        FOREIGN KEY (`referrer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `shop_referrals_referred_user_id_foreign`
        FOREIGN KEY (`referred_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `shop_referrals_referred_atelier_id_foreign`
        FOREIGN KEY (`referred_atelier_id`) REFERENCES `ateliers` (`id`) ON DELETE SET NULL
);
