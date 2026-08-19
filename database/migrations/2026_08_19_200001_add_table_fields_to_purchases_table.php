<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTableFieldsToPurchasesTable extends Migration
{
    public function up()
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('table_label', 100)->nullable()->after('atelier_id');
            $table->unsignedBigInteger('shop_table_id')->nullable()->after('table_label');

            $table->index('shop_table_id');
        });
    }

    public function down()
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['shop_table_id']);
            $table->dropColumn(['table_label', 'shop_table_id']);
        });
    }
}
