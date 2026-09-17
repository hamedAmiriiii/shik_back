<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('returned_products')) {
            return;
        }

        DB::statement('ALTER TABLE `returned_products` MODIFY COLUMN `sale_price` DECIMAL(15, 2) NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('returned_products')) {
            return;
        }

        DB::statement('ALTER TABLE `returned_products` MODIFY COLUMN `sale_price` DECIMAL(10, 2) NOT NULL');
    }
};
