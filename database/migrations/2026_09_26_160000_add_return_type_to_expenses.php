<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expenses') || ! Schema::hasColumn('expenses', 'type')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            $column = DB::selectOne("SHOW COLUMNS FROM expenses WHERE Field = 'type'");
            $sqlType = strtolower((string) ($column->Type ?? ''));
            if (str_starts_with($sqlType, 'enum(') && ! str_contains($sqlType, 'برگشت')) {
                DB::statement("ALTER TABLE expenses MODIFY `type` ENUM('جاری','سرمایه','برگشت') NOT NULL DEFAULT 'جاری'");
            }
        }

        if (Schema::hasColumn('expenses', 'credit_source')) {
            DB::table('expenses')
                ->where('credit_source', 'purchase_return')
                ->update(['type' => 'برگشت']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('expenses') || ! Schema::hasColumn('expenses', 'type')) {
            return;
        }

        if (Schema::hasColumn('expenses', 'credit_source')) {
            DB::table('expenses')
                ->where('credit_source', 'purchase_return')
                ->update(['type' => 'جاری']);
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

        $column = DB::selectOne("SHOW COLUMNS FROM expenses WHERE Field = 'type'");
        $sqlType = strtolower((string) ($column->Type ?? ''));
        if (str_starts_with($sqlType, 'enum(') && str_contains($sqlType, 'برگشت')) {
            DB::statement("ALTER TABLE expenses MODIFY `type` ENUM('جاری','سرمایه') NOT NULL DEFAULT 'جاری'");
        }
    }
};
