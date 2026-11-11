-- یک‌بار اجرا شود. اگر ستون از قبل باشد، ALTER خطا می‌دهد.
-- کالای انبار مصرفی در فرمول کالای تولیدی، و اتصال برداشت به همان تولید (برای برگشت موجودی).

ALTER TABLE `produced_good_ingredients`
  ADD COLUMN `consumable_material_id` BIGINT UNSIGNED NULL AFTER `ingredient_produced_good_id`,
  ADD UNIQUE KEY `produced_good_ingredients_consumable_unique` (`produced_good_id`, `consumable_material_id`),
  ADD CONSTRAINT `produced_good_ingredients_consumable_fk`
    FOREIGN KEY (`consumable_material_id`) REFERENCES `consumable_materials` (`id`) ON DELETE RESTRICT;

ALTER TABLE `consumable_withdrawals`
  ADD COLUMN `production_id` BIGINT UNSIGNED NULL AFTER `consumable_material_id`,
  ADD KEY `consumable_withdrawals_production_id` (`production_id`),
  ADD CONSTRAINT `consumable_withdrawals_production_fk`
    FOREIGN KEY (`production_id`) REFERENCES `productions` (`id`) ON DELETE SET NULL;
