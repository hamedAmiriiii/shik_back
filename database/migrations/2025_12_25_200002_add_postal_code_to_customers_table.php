<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPostalCodeToCustomersTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('customers') || Schema::hasColumn('customers', 'postal_code')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->string('postal_code', 10)->nullable()->after('address');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'postal_code')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('postal_code');
        });
    }
}
