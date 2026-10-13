-- اجازه اضافه کردن ماده در منوی سه‌بعدی (مثل دوبل کردن)
-- اگر ستون از قبل هست خطای Duplicate column می‌دهد و می‌توانید نادیده بگیرید.

ALTER TABLE `produced_good_ingredients`
  ADD COLUMN `is_addable` TINYINT(1) NOT NULL DEFAULT 0
  AFTER `is_removable`;
