<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDetailsToReturnedProductsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('returned_products')) {
            return;
        }

        if (! Schema::hasColumn('returned_products', 'purchase_price')) {
            Schema::table('returned_products', function (Blueprint $table) {
                $table->decimal('purchase_price', 15, 2)->default(0)->after('sale_price');
            });
        }
        if (! Schema::hasColumn('returned_products', 'user_name')) {
            Schema::table('returned_products', function (Blueprint $table) {
                $table->string('user_name')->nullable()->after('purchase_price');
            });
        }
        if (! Schema::hasColumn('returned_products', 'notes')) {
            Schema::table('returned_products', function (Blueprint $table) {
                $table->text('notes')->nullable()->after('user_name');
            });
        }
    }

    public function down()
    {
        // no-op: columns may have been part of original create
    }
}
