<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSalePriceToPurchasedProductsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('purchased_products') || Schema::hasColumn('purchased_products', 'sale_price')) {
            return;
        }

        Schema::table('purchased_products', function (Blueprint $table) {
            $table->decimal('sale_price', 15, 2)->after('purchase_price');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('purchased_products') || ! Schema::hasColumn('purchased_products', 'sale_price')) {
            return;
        }

        Schema::table('purchased_products', function (Blueprint $table) {
            $table->dropColumn('sale_price');
        });
    }
}
