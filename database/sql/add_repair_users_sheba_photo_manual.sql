-- شبا جدا از شماره کارت + عکس سلفی تعمیرکار

ALTER TABLE `repair_users`
  ADD COLUMN `sheba` varchar(34) DEFAULT NULL AFTER `card_number`,
  ADD COLUMN `photo_path` varchar(255) DEFAULT NULL AFTER `sheba`;

-- شماره‌های شبایی که قبلاً در فیلد کارت ثبت شده بودند به فیلد شبا منتقل می‌شوند
UPDATE `repair_users`
SET `sheba` = CONCAT('IR', REPLACE(REPLACE(REPLACE(UPPER(`card_number`), 'IR', ''), '-', ''), ' ', '')),
    `card_number` = NULL
WHERE `card_number` IS NOT NULL
  AND LENGTH(REPLACE(REPLACE(REPLACE(UPPER(`card_number`), 'IR', ''), '-', ''), ' ', '')) = 24;
