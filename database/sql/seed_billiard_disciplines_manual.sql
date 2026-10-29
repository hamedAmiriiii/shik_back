-- رشته‌های اولیه هیأت بیلیارد شهرستان کرمان
INSERT INTO `billiard_disciplines` (`name`, `slug`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'اسنوکر', 'snooker', 1, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `billiard_disciplines` WHERE `slug` = 'snooker');

INSERT INTO `billiard_disciplines` (`name`, `slug`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'پاکت', 'pocket', 1, 2, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `billiard_disciplines` WHERE `slug` = 'pocket');
