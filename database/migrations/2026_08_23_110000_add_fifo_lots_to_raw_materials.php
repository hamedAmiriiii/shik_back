<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddFifoLotsToRawMaterials extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('raw_material_lots')) {
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
        }

        if (Schema::hasTable('raw_materials') && Schema::hasColumn('raw_materials', 'price_per_kg')) {
            if (Schema::hasTable('raw_material_lots')) {
                $rows = DB::table('raw_materials')
                    ->where(function ($q) {
                        $q->where('stock_kg', '>', 0)->orWhere('price_per_kg', '>', 0);
                    })
                    ->get();

                foreach ($rows as $row) {
                    $stock = (float) ($row->stock_kg ?? 0);
                    if ($stock <= 0) {
                        continue;
                    }

                    $exists = DB::table('raw_material_lots')
                        ->where('raw_material_id', $row->id)
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    $now = now();
                    DB::table('raw_material_lots')->insert([
                        'atelier_id' => $row->atelier_id,
                        'raw_material_id' => $row->id,
                        'quantity_kg' => $stock,
                        'remaining_kg' => $stock,
                        'price_per_kg' => $row->price_per_kg ?? 0,
                        'purchased_at' => $row->created_at ?? $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            Schema::table('raw_materials', function (Blueprint $table) {
                $table->dropColumn(['price_per_kg', 'stock_kg']);
            });
        }

        if (! Schema::hasTable('productions')) {
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
        }

        if (! Schema::hasTable('production_consumptions')) {
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
    }

    public function down()
    {
        Schema::dropIfExists('production_consumptions');
        Schema::dropIfExists('productions');

        if (Schema::hasTable('raw_materials') && ! Schema::hasColumn('raw_materials', 'price_per_kg')) {
            Schema::table('raw_materials', function (Blueprint $table) {
                $table->decimal('price_per_kg', 15, 2)->default(0);
                $table->decimal('stock_kg', 12, 3)->default(0);
            });
        }

        Schema::dropIfExists('raw_material_lots');
    }
}
