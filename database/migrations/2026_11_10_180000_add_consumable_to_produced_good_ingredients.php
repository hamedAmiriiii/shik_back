<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddConsumableToProducedGoodIngredients extends Migration
{
    public function up()
    {
        if (Schema::hasTable('produced_good_ingredients')
            && Schema::hasTable('consumable_materials')
            && ! Schema::hasColumn('produced_good_ingredients', 'consumable_material_id')) {
            $after = Schema::hasColumn('produced_good_ingredients', 'ingredient_produced_good_id')
                ? 'ingredient_produced_good_id'
                : 'raw_material_id';
            Schema::table('produced_good_ingredients', function (Blueprint $table) use ($after) {
                $table->unsignedBigInteger('consumable_material_id')->nullable()->after($after);
                $table->unique(
                    ['produced_good_id', 'consumable_material_id'],
                    'produced_good_ingredients_consumable_unique'
                );
                $table->foreign('consumable_material_id', 'produced_good_ingredients_consumable_fk')
                    ->references('id')->on('consumable_materials')->onDelete('restrict');
            });
        }

        if (Schema::hasTable('consumable_withdrawals')
            && Schema::hasTable('productions')
            && ! Schema::hasColumn('consumable_withdrawals', 'production_id')) {
            Schema::table('consumable_withdrawals', function (Blueprint $table) {
                $table->unsignedBigInteger('production_id')->nullable()->after('consumable_material_id');
                $table->index('production_id', 'consumable_withdrawals_production_id');
                $table->foreign('production_id', 'consumable_withdrawals_production_fk')
                    ->references('id')->on('productions')->onDelete('set null');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('consumable_withdrawals')
            && Schema::hasColumn('consumable_withdrawals', 'production_id')) {
            Schema::table('consumable_withdrawals', function (Blueprint $table) {
                $table->dropForeign('consumable_withdrawals_production_fk');
                $table->dropIndex('consumable_withdrawals_production_id');
                $table->dropColumn('production_id');
            });
        }

        if (Schema::hasTable('produced_good_ingredients')
            && Schema::hasColumn('produced_good_ingredients', 'consumable_material_id')) {
            Schema::table('produced_good_ingredients', function (Blueprint $table) {
                $table->dropForeign('produced_good_ingredients_consumable_fk');
                $table->dropUnique('produced_good_ingredients_consumable_unique');
                $table->dropColumn('consumable_material_id');
            });
        }
    }
}
