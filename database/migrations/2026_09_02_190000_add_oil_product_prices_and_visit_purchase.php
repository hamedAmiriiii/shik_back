<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOilProductPricesAndVisitPurchase extends Migration
{
    public function up()
    {
        if (Schema::hasTable('oil_products')) {
            Schema::table('oil_products', function (Blueprint $table) {
                if (! Schema::hasColumn('oil_products', 'purchase_price')) {
                    $table->decimal('purchase_price', 15, 2)->default(0)->after('name');
                }
                if (! Schema::hasColumn('oil_products', 'sale_price')) {
                    $table->decimal('sale_price', 15, 2)->default(0)->after('purchase_price');
                }
            });
        }

        if (Schema::hasTable('oil_visit_items')) {
            Schema::table('oil_visit_items', function (Blueprint $table) {
                if (! Schema::hasColumn('oil_visit_items', 'purchase_price')) {
                    $table->decimal('purchase_price', 15, 2)->default(0)->after('product_name');
                }
                if (! Schema::hasColumn('oil_visit_items', 'sale_price')) {
                    $table->decimal('sale_price', 15, 2)->default(0)->after('purchase_price');
                }
            });
        }

        if (Schema::hasTable('purchases') && ! Schema::hasColumn('purchases', 'oil_visit_id')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->unsignedBigInteger('oil_visit_id')->nullable()->after('atelier_id');
                $table->unique('oil_visit_id');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'oil_visit_id')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->dropUnique(['oil_visit_id']);
                $table->dropColumn('oil_visit_id');
            });
        }
        if (Schema::hasTable('oil_visit_items')) {
            Schema::table('oil_visit_items', function (Blueprint $table) {
                if (Schema::hasColumn('oil_visit_items', 'sale_price')) {
                    $table->dropColumn('sale_price');
                }
                if (Schema::hasColumn('oil_visit_items', 'purchase_price')) {
                    $table->dropColumn('purchase_price');
                }
            });
        }
        if (Schema::hasTable('oil_products')) {
            Schema::table('oil_products', function (Blueprint $table) {
                if (Schema::hasColumn('oil_products', 'sale_price')) {
                    $table->dropColumn('sale_price');
                }
                if (Schema::hasColumn('oil_products', 'purchase_price')) {
                    $table->dropColumn('purchase_price');
                }
            });
        }
    }
}
