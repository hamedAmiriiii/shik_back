<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInventoryWastesTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('inventory_wastes')) {
            Schema::create('inventory_wastes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->string('kind', 20); // raw_material | produced_good
                $table->unsignedBigInteger('raw_material_id')->nullable();
                $table->unsignedBigInteger('produced_good_id')->nullable();
                $table->decimal('quantity_kg', 12, 3);
                $table->decimal('total_cost', 15, 2)->default(0);
                $table->text('note')->nullable();
                $table->timestamp('wasted_at')->nullable();
                $table->timestamps();

                $table->index(['atelier_id', 'wasted_at'], 'inventory_wastes_atelier_date_idx');
                $table->index(['atelier_id', 'kind'], 'inventory_wastes_atelier_kind_idx');
                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
                $table->foreign('raw_material_id')->references('id')->on('raw_materials')->onDelete('restrict');
                $table->foreign('produced_good_id')->references('id')->on('produced_goods')->onDelete('restrict');
            });
        }

        if (! Schema::hasTable('inventory_waste_consumptions')) {
            Schema::create('inventory_waste_consumptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('inventory_waste_id');
                $table->unsignedBigInteger('raw_material_lot_id')->nullable();
                $table->unsignedBigInteger('production_id')->nullable();
                $table->decimal('quantity_kg', 12, 3);
                $table->decimal('price_per_kg', 15, 2);
                $table->decimal('cost', 15, 2);
                $table->timestamps();

                $table->index('inventory_waste_id', 'iwc_waste_idx');
                $table->index('raw_material_lot_id', 'iwc_lot_idx');
                $table->index('production_id', 'iwc_production_idx');
                $table->foreign('inventory_waste_id', 'iwc_waste_fk')
                    ->references('id')
                    ->on('inventory_wastes')
                    ->cascadeOnDelete();
                $table->foreign('raw_material_lot_id', 'iwc_lot_fk')
                    ->references('id')
                    ->on('raw_material_lots')
                    ->onDelete('restrict');
                $table->foreign('production_id', 'iwc_production_fk')
                    ->references('id')
                    ->on('productions')
                    ->onDelete('restrict');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('inventory_waste_consumptions');
        Schema::dropIfExists('inventory_wastes');
    }
}
