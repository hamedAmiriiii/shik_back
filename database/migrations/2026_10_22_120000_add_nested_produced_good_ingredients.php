<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddNestedProducedGoodIngredients extends Migration
{
    public function up()
    {
        if (Schema::hasTable('produced_good_ingredients')
            && ! Schema::hasColumn('produced_good_ingredients', 'ingredient_produced_good_id')) {
            Schema::table('produced_good_ingredients', function (Blueprint $table) {
                $table->unsignedBigInteger('ingredient_produced_good_id')->nullable()->after('raw_material_id');
                $table->index('ingredient_produced_good_id', 'pgi_ingredient_good_idx');
            });

            // MySQL: اجازهٔ NULL برای ماده اولیه تا خط فرمول بتواند کالای تولیدی باشد.
            DB::statement('ALTER TABLE `produced_good_ingredients` MODIFY `raw_material_id` BIGINT UNSIGNED NULL');

            Schema::table('produced_good_ingredients', function (Blueprint $table) {
                $table->foreign('ingredient_produced_good_id', 'pgi_ingredient_good_fk')
                    ->references('id')
                    ->on('produced_goods')
                    ->onDelete('restrict');
                $table->unique(
                    ['produced_good_id', 'ingredient_produced_good_id'],
                    'produced_good_ingredients_nested_unique'
                );
            });
        }

        if (Schema::hasTable('production_consumptions')
            && ! Schema::hasColumn('production_consumptions', 'ingredient_produced_good_id')) {
            Schema::table('production_consumptions', function (Blueprint $table) {
                $table->unsignedBigInteger('ingredient_produced_good_id')->nullable()->after('raw_material_lot_id');
                $table->unsignedBigInteger('source_production_id')->nullable()->after('ingredient_produced_good_id');
                $table->index('ingredient_produced_good_id', 'pc_ingredient_good_idx');
                $table->index('source_production_id', 'pc_source_production_idx');
            });

            DB::statement('ALTER TABLE `production_consumptions` MODIFY `raw_material_id` BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE `production_consumptions` MODIFY `raw_material_lot_id` BIGINT UNSIGNED NULL');

            Schema::table('production_consumptions', function (Blueprint $table) {
                $table->foreign('ingredient_produced_good_id', 'pc_ingredient_good_fk')
                    ->references('id')
                    ->on('produced_goods')
                    ->onDelete('restrict');
                $table->foreign('source_production_id', 'pc_source_production_fk')
                    ->references('id')
                    ->on('productions')
                    ->onDelete('restrict');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('production_consumptions')
            && Schema::hasColumn('production_consumptions', 'ingredient_produced_good_id')) {
            Schema::table('production_consumptions', function (Blueprint $table) {
                $table->dropForeign('pc_source_production_fk');
                $table->dropForeign('pc_ingredient_good_fk');
                $table->dropIndex('pc_source_production_idx');
                $table->dropIndex('pc_ingredient_good_idx');
                $table->dropColumn(['ingredient_produced_good_id', 'source_production_id']);
            });
        }

        if (Schema::hasTable('produced_good_ingredients')
            && Schema::hasColumn('produced_good_ingredients', 'ingredient_produced_good_id')) {
            Schema::table('produced_good_ingredients', function (Blueprint $table) {
                $table->dropForeign('pgi_ingredient_good_fk');
                $table->dropUnique('produced_good_ingredients_nested_unique');
                $table->dropIndex('pgi_ingredient_good_idx');
                $table->dropColumn('ingredient_produced_good_id');
            });
        }
    }
}
