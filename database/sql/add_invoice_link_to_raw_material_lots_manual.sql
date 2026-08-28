-- اتصال خرید ماده اولیه به فاکتور (کل فاکتور یا یک آیتم)
-- معادل migration 2026_08_28_141000
-- هر دستور را جدا اجرا کنید.
-- اگر خطای Duplicate column / Duplicate key / Duplicate foreign key آمد، همان دستور را رد کنید و بعدی را بزنید.

-- اگر invoice_id از قبل هست (#1060 Duplicate column) این را رد کنید.
ALTER TABLE `raw_material_lots`
    ADD COLUMN `invoice_id` BIGINT UNSIGNED NULL AFTER `note`;

-- اگر invoice_item_id از قبل هست این را رد کنید.
ALTER TABLE `raw_material_lots`
    ADD COLUMN `invoice_item_id` BIGINT UNSIGNED NULL AFTER `invoice_id`;

-- اگر ایندکس از قبل هست (#1061 Duplicate key) این را رد کنید.
ALTER TABLE `raw_material_lots`
    ADD KEY `raw_material_lots_invoice_id` (`invoice_id`);

ALTER TABLE `raw_material_lots`
    ADD KEY `raw_material_lots_invoice_item_id` (`invoice_item_id`);

-- اگر قید از قبل هست (#1826 Duplicate foreign key) این را رد کنید.
ALTER TABLE `raw_material_lots`
    ADD CONSTRAINT `raw_material_lots_invoice_fk`
        FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL;

ALTER TABLE `raw_material_lots`
    ADD CONSTRAINT `raw_material_lots_invoice_item_fk`
        FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE SET NULL;
