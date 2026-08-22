<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRawMaterialsAndProducedGoodsTables extends Migration
{
    public function up()
    {
        Schema::create('raw_materials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->string('name');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['atelier_id', 'name'], 'raw_materials_atelier_name_unique');
            $table->index(['atelier_id', 'name']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
        });

        Schema::create('raw_material_lots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('raw_material_id');
            $table->decimal('quantity_kg', 12, 3);
            $table->decimal('remaining_kg', 12, 3);
            $table->decimal('price_per_kg', 15, 2);
            $table->timestamp('purchased_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['raw_material_id', 'purchased_at', 'id'], 'raw_material_lots_fifo');
            $table->index(['atelier_id', 'raw_material_id']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            $table->foreign('raw_material_id')->references('id')->on('raw_materials')->cascadeOnDelete();
        });

        Schema::create('produced_goods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->string('name');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['atelier_id', 'name'], 'produced_goods_atelier_name_unique');
            $table->index(['atelier_id', 'name']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
        });

        Schema::create('produced_good_ingredients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('produced_good_id');
            $table->unsignedBigInteger('raw_material_id');
            $table->decimal('grams_per_kg', 12, 3);
            $table->timestamps();

            $table->unique(['produced_good_id', 'raw_material_id'], 'produced_good_ingredients_unique');
            $table->index('raw_material_id');
            $table->foreign('produced_good_id')->references('id')->on('produced_goods')->cascadeOnDelete();
            $table->foreign('raw_material_id')->references('id')->on('raw_materials')->onDelete('restrict');
        });

        Schema::create('productions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('produced_good_id');
            $table->decimal('quantity_kg', 12, 3);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->decimal('cost_per_kg', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['atelier_id', 'produced_good_id']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            $table->foreign('produced_good_id')->references('id')->on('produced_goods')->cascadeOnDelete();
        });

        Schema::create('production_consumptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('production_id');
            $table->unsignedBigInteger('raw_material_id');
            $table->unsignedBigInteger('raw_material_lot_id');
            $table->decimal('quantity_kg', 12, 3);
            $table->decimal('price_per_kg', 15, 2);
            $table->decimal('cost', 15, 2);
            $table->timestamps();

            $table->index('production_id');
            $table->index('raw_material_lot_id');
            $table->foreign('production_id')->references('id')->on('productions')->cascadeOnDelete();
            $table->foreign('raw_material_id')->references('id')->on('raw_materials')->onDelete('restrict');
            $table->foreign('raw_material_lot_id')->references('id')->on('raw_material_lots')->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::dropIfExists('production_consumptions');
        Schema::dropIfExists('productions');
        Schema::dropIfExists('produced_good_ingredients');
        Schema::dropIfExists('produced_goods');
        Schema::dropIfExists('raw_material_lots');
        Schema::dropIfExists('raw_materials');
    }
}
