<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDetailsToReturnedProductsTable extends Migration
{
    public function up()
    {
        Schema::table('returned_products', function (Blueprint $table) {
            $table->decimal('purchase_price', 15, 2)->default(0)->after('sale_price');
            $table->string('user_name')->nullable()->after('purchase_price');
            $table->text('notes')->nullable()->after('user_name');
        });
    }

    public function down()
    {
        Schema::table('returned_products', function (Blueprint $table) {
            $table->dropColumn(['purchase_price', 'user_name', 'notes']);
        });
    }
}
