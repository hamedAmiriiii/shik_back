-- کالای تولیدی به‌عنوان ماده در فرمول کالای دیگر (نیمه‌ساخته / BOM تو در تو)

ALTER TABLE `produced_good_ingredients`
  MODIFY `raw_material_id` BIGINT UNSIGNED NULL;

ALTER TABLE `produced_good_ingredients`
  ADD COLUMN `ingredient_produced_good_id` BIGINT UNSIGNED NULL AFTER `raw_material_id`,
  ADD INDEX `pgi_ingredient_good_idx` (`ingredient_produced_good_id`),
  ADD UNIQUE KEY `produced_good_ingredients_nested_unique` (`produced_good_id`, `ingredient_produced_good_id`),
  ADD CONSTRAINT `pgi_ingredient_good_fk`
    FOREIGN KEY (`ingredient_produced_good_id`) REFERENCES `produced_goods` (`id`) ON DELETE RESTRICT;

ALTER TABLE `production_consumptions`
  MODIFY `raw_material_id` BIGINT UNSIGNED NULL,
  MODIFY `raw_material_lot_id` BIGINT UNSIGNED NULL;

ALTER TABLE `production_consumptions`
  ADD COLUMN `ingredient_produced_good_id` BIGINT UNSIGNED NULL AFTER `raw_material_lot_id`,
  ADD COLUMN `source_production_id` BIGINT UNSIGNED NULL AFTER `ingredient_produced_good_id`,
  ADD INDEX `pc_ingredient_good_idx` (`ingredient_produced_good_id`),
  ADD INDEX `pc_source_production_idx` (`source_production_id`),
  ADD CONSTRAINT `pc_ingredient_good_fk`
    FOREIGN KEY (`ingredient_produced_good_id`) REFERENCES `produced_goods` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `pc_source_production_fk`
    FOREIGN KEY (`source_production_id`) REFERENCES `productions` (`id`) ON DELETE RESTRICT;
