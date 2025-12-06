<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLoyaltyFieldsToPurchasedProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('purchased_products', function (Blueprint $table) {
            $table->string('phone', 11)->nullable()->after('purchase_price');
            $table->decimal('total_amount', 15, 2)->nullable()->after('phone');
            $table->decimal('credit_used', 15, 2)->default(0)->after('total_amount');
            $table->decimal('credit_earned', 15, 2)->default(0)->after('credit_used');
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
            $table->dropColumn(['phone', 'total_amount', 'credit_used', 'credit_earned']);
        });
    }
}

