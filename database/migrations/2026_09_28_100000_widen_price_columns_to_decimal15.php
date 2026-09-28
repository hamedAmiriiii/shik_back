<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DECIMAL(10,2) فقط تا ۹۹٬۹۹۹٬۹۹۹٫۹۹ جا دارد؛ کالای ۱۶۰ میلیونی با خطای 1264 ذخیره نمی‌شد.
 * همه ستون‌های مبلغ هم‌اندازهٔ بقیه (DECIMAL(15,2)) می‌شوند. nullable و default فعلی حفظ می‌شود.
 */
return new class extends Migration
{
    private const COLUMNS = [
        ['products', 'purchase_price'],
        ['products', 'sale_price'],
        ['purchased_products', 'purchase_price'],
        ['purchases', 'installment_amount'],
        ['installments', 'amount'],
        ['expenses', 'amount'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            $this->resize($table, $column, 15);
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            $this->resize($table, $column, 10);
        }
    }

    private function resize(string $table, string $column, int $precision): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $info = DB::selectOne(
            'SELECT NUMERIC_PRECISION, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        if (! $info || (int) $info->NUMERIC_PRECISION === $precision) {
            return;
        }

        $sql = "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` DECIMAL({$precision}, 2)";
        $sql .= $info->IS_NULLABLE === 'YES' ? ' NULL' : ' NOT NULL';
        if ($info->COLUMN_DEFAULT !== null) {
            $sql .= ' DEFAULT '.DB::getPdo()->quote(trim((string) $info->COLUMN_DEFAULT, "'"));
        }

        DB::statement($sql);
    }
};
