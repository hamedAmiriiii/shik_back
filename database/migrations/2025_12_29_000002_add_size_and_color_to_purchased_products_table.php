<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSizeAndColorToPurchasedProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('purchased_products', function (Blueprint $table) {
            $table->string('size')->nullable()->after('sale_price'); // سایز انتخاب شده
            $table->string('color')->nullable()->after('size'); // رنگ انتخاب شده
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('purchased_products', function (Blueprint $table) {
            $table->dropColumn(['size', 'color']);
        });
    }
}

