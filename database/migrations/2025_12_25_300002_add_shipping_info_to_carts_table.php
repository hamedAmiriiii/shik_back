<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShippingInfoToCartsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->string('shipping_name')->nullable()->after('status');
            $table->string('shipping_last_name')->nullable()->after('shipping_name');
            $table->string('shipping_phone', 11)->nullable()->after('shipping_last_name');
            $table->text('shipping_address')->nullable()->after('shipping_phone');
            $table->unsignedBigInteger('shipping_state_id')->nullable()->after('shipping_address');
            $table->string('shipping_state_name')->nullable()->after('shipping_state_id');
            $table->unsignedBigInteger('shipping_city_id')->nullable()->after('shipping_state_name');
            $table->string('shipping_city_name')->nullable()->after('shipping_city_id');
            $table->string('shipping_postal_code', 10)->nullable()->after('shipping_city_name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_name',
                'shipping_last_name',
                'shipping_phone',
                'shipping_address',
                'shipping_state_id',
                'shipping_state_name',
                'shipping_city_id',
                'shipping_city_name',
                'shipping_postal_code'
            ]);
        });
    }
}

