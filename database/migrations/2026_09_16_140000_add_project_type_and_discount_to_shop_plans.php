<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProjectTypeAndDiscountToShopPlans extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('shop_plans')) {
            return;
        }

        Schema::table('shop_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_plans', 'project_type')) {
                $table->string('project_type', 16)->default('shop')->after('name');
                $table->index('project_type');
            }
            if (! Schema::hasColumn('shop_plans', 'discount_price_rial')) {
                $table->unsignedBigInteger('discount_price_rial')->nullable()->after('price_rial');
            }
            if (! Schema::hasColumn('shop_plans', 'description')) {
                $table->string('description', 500)->nullable()->after('discount_price_rial');
            }
        });
    }

    public function down()
    {
        if (! Schema::hasTable('shop_plans')) {
            return;
        }

        Schema::table('shop_plans', function (Blueprint $table) {
            if (Schema::hasColumn('shop_plans', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('shop_plans', 'discount_price_rial')) {
                $table->dropColumn('discount_price_rial');
            }
            if (Schema::hasColumn('shop_plans', 'project_type')) {
                $table->dropColumn('project_type');
            }
        });
    }
}
