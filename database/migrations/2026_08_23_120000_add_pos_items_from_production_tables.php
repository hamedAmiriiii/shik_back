<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddPosItemsFromProductionTables extends Migration
{
    public function up()
    {
        if (Schema::hasTable('produced_goods') && ! Schema::hasColumn('produced_goods', 'sale_price')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->decimal('sale_price', 15, 2)->default(0)->after('name');
            });
        }

        if (Schema::hasTable('raw_materials') && ! Schema::hasColumn('raw_materials', 'sale_price')) {
            Schema::table('raw_materials', function (Blueprint $table) {
                $table->decimal('sale_price', 15, 2)->default(0)->after('name');
            });
        }

        if (Schema::hasTable('productions') && ! Schema::hasColumn('productions', 'remaining_kg')) {
            Schema::table('productions', function (Blueprint $table) {
                $table->decimal('remaining_kg', 12, 3)->default(0)->after('quantity_kg');
            });
            DB::statement('UPDATE productions SET remaining_kg = quantity_kg WHERE remaining_kg = 0');
        }

        if (Schema::hasTable('purchased_products')) {
            Schema::table('purchased_products', function (Blueprint $table) {
                if (! Schema::hasColumn('purchased_products', 'produced_good_id')) {
                    $table->unsignedBigInteger('produced_good_id')->nullable()->after('product_id');
                }
                if (! Schema::hasColumn('purchased_products', 'raw_material_id')) {
                    $table->unsignedBigInteger('raw_material_id')->nullable()->after('produced_good_id');
                }
                if (! Schema::hasColumn('purchased_products', 'item_name')) {
                    $table->string('item_name')->nullable()->after('raw_material_id');
                }
            });

            DB::statement('ALTER TABLE purchased_products MODIFY product_id BIGINT UNSIGNED NULL');
        }

        if (! Schema::hasTable('purchase_stock_consumptions')) {
            Schema::create('purchase_stock_consumptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('purchased_product_id');
                $table->unsignedBigInteger('production_id')->nullable();
                $table->unsignedBigInteger('raw_material_lot_id')->nullable();
                $table->decimal('quantity_kg', 12, 3);
                $table->decimal('restored_kg', 12, 3)->default(0);
                $table->decimal('price_per_kg', 15, 2);
                $table->decimal('cost', 15, 2);
                $table->timestamps();

                $table->index('purchased_product_id');
                $table->foreign('purchased_product_id')->references('id')->on('purchased_products')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('purchase_item_returns')) {
            Schema::table('purchase_item_returns', function (Blueprint $table) {
                if (! Schema::hasColumn('purchase_item_returns', 'produced_good_id')) {
                    $table->unsignedBigInteger('produced_good_id')->nullable()->after('product_id');
                }
                if (! Schema::hasColumn('purchase_item_returns', 'raw_material_id')) {
                    $table->unsignedBigInteger('raw_material_id')->nullable()->after('produced_good_id');
                }
            });
            DB::statement('ALTER TABLE purchase_item_returns MODIFY product_id BIGINT UNSIGNED NULL');
        }
    }

    public function down()
    {
        Schema::dropIfExists('purchase_stock_consumptions');
    }
}
