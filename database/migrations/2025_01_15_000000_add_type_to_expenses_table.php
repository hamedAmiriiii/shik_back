<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTypeToExpensesTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('expenses') || Schema::hasColumn('expenses', 'type')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->enum('type', ['جاری', 'سرمایه'])->default('جاری')->after('title');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('expenses') || ! Schema::hasColumn('expenses', 'type')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
}
