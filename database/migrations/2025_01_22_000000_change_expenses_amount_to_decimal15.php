<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChangeExpensesAmountToDecimal15 extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('expenses') || ! Schema::hasColumn('expenses', 'amount')) {
            return;
        }

        DB::statement('ALTER TABLE `expenses` MODIFY `amount` DECIMAL(15, 2) NOT NULL');
    }

    public function down()
    {
        if (! Schema::hasTable('expenses') || ! Schema::hasColumn('expenses', 'amount')) {
            return;
        }

        DB::statement('ALTER TABLE `expenses` MODIFY `amount` DECIMAL(10, 2) NOT NULL');
    }
}
