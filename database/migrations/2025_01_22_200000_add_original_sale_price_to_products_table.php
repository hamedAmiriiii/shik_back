<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOriginalSalePriceToProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('original_sale_price', 15, 2)->nullable()->after('sale_price');
        });

        // برای داده‌های موجود، original_sale_price را برابر sale_price قرار بده
        \Illuminate\Support\Facades\DB::statement('UPDATE products SET original_sale_price = sale_price WHERE original_sale_price IS NULL');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('original_sale_price');
        });
    }
}

