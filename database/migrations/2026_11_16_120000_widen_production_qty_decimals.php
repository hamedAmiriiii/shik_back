<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WidenProductionQtyDecimals extends Migration
{
    public function up()
    {
        $alters = [
            'produced_good_ingredients' => [
                'MODIFY COLUMN `grams_per_kg` DECIMAL(14,6) NOT NULL',
            ],
            'raw_material_lots' => [
                'MODIFY COLUMN `quantity_kg` DECIMAL(14,6) NOT NULL',
                'MODIFY COLUMN `remaining_kg` DECIMAL(14,6) NOT NULL',
            ],
            'productions' => [
                'MODIFY COLUMN `quantity_kg` DECIMAL(14,6) NOT NULL',
                'MODIFY COLUMN `remaining_kg` DECIMAL(14,6) NOT NULL DEFAULT 0',
            ],
            'production_consumptions' => [
                'MODIFY COLUMN `quantity_kg` DECIMAL(14,6) NOT NULL',
            ],
            'purchase_stock_consumptions' => [
                'MODIFY COLUMN `quantity_kg` DECIMAL(14,6) NOT NULL',
                'MODIFY COLUMN `restored_kg` DECIMAL(14,6) NOT NULL DEFAULT 0',
            ],
        ];

        foreach ($alters as $table => $statements) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($statements as $sql) {
                try {
                    DB::statement("ALTER TABLE `{$table}` {$sql}");
                } catch (\Throwable $e) {
                    // ستون از قبل عریض شده یا موتور قدیمی — ادامه بده
                }
            }
        }
    }

    public function down()
    {
        // برگشت به ۳ رقم ضرورتی ندارد؛ ممکن است دادهٔ ریزتر از بین برود.
    }
}
