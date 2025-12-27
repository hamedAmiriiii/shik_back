-- SQL برای اضافه کردن ستون password به جدول customers

ALTER TABLE `customers` 
ADD COLUMN `password` VARCHAR(255) NOT NULL AFTER `phone`;

