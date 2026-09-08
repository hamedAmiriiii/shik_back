<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddManufacturerIdToProductsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'manufacturer_id')) {
            return;
        }
        if (! Schema::hasTable('manufacturers')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('manufacturer_id')->nullable()->after('name')->constrained('manufacturers')->onDelete('set null');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'manufacturer_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['manufacturer_id']);
            $table->dropColumn('manufacturer_id');
        });
    }
}
