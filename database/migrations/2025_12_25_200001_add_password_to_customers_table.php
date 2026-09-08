<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPasswordToCustomersTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('customers') || Schema::hasColumn('customers', 'password')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->string('password')->after('phone');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'password')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
}
