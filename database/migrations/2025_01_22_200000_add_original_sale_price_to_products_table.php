<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddOriginalSalePriceToProductsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('products')) {
            return;
        }
        if (! Schema::hasColumn('products', 'original_sale_price')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('original_sale_price', 15, 2)->nullable()->after('sale_price');
            });
        }

        DB::statement('UPDATE products SET original_sale_price = sale_price WHERE original_sale_price IS NULL');
    }

    public function down()
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'original_sale_price')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('original_sale_price');
        });
    }
}
