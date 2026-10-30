-- فیلدهای مرحله گروهی
ALTER TABLE `billiard_tournaments`
  ADD COLUMN `group_count` tinyint unsigned NULL AFTER `format`;

ALTER TABLE `billiard_registrations`
  ADD COLUMN `group_code` varchar(8) NULL AFTER `seed`;
