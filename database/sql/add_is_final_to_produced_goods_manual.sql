-- کالای نهایی (منو/فروش) در مقابل نیمه‌ساخته (مصرف در فرمول تولید)

ALTER TABLE `produced_goods`
  ADD COLUMN `is_final` TINYINT(1) NOT NULL DEFAULT 1 AFTER `unit_type`,
  ADD INDEX `produced_goods_atelier_final_idx` (`atelier_id`, `is_final`);
