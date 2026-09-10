<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDescriptionToProductsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('products', 'description')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('description', 500)->nullable()->after('name');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('products', 'description')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
}
