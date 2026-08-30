<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCategoryAndDiscountToProducedGoods extends Migration
{
    public function up()
    {
        if (Schema::hasTable('produced_goods') && ! Schema::hasColumn('produced_goods', 'original_sale_price')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->decimal('original_sale_price', 15, 2)->nullable()->after('sale_price');
            });
        }

        if (! Schema::hasTable('category_produced_good')) {
            Schema::create('category_produced_good', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('category_id');
                $table->unsignedBigInteger('produced_good_id');
                $table->timestamps();
                $table->unique(['category_id', 'produced_good_id'], 'category_produced_good_unique');
                $table->index('produced_good_id');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('category_produced_good');

        if (Schema::hasTable('produced_goods') && Schema::hasColumn('produced_goods', 'original_sale_price')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->dropColumn('original_sale_price');
            });
        }
    }
}
