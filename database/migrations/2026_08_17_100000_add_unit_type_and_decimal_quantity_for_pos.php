<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddUnitTypeAndDecimalQuantityForPos extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('unit_type', 10)->default('piece')->after('quantity');
        });

        DB::statement('ALTER TABLE products MODIFY quantity DECIMAL(12, 3) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE purchased_products MODIFY quantity DECIMAL(12, 3) NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE purchase_item_returns MODIFY quantity DECIMAL(12, 3) NOT NULL');
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('unit_type');
        });

        DB::statement('ALTER TABLE products MODIFY quantity INT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE purchased_products MODIFY quantity INT NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE purchase_item_returns MODIFY quantity INT UNSIGNED NOT NULL');
    }
}
