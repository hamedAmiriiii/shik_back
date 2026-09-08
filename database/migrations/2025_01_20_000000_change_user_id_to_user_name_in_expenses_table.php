<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeUserIdToUserNameInExpensesTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }
        if (! Schema::hasColumn('expenses', 'user_id') || Schema::hasColumn('expenses', 'user_name')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
            $table->string('user_name')->after('id');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('expenses') || ! Schema::hasColumn('expenses', 'user_name')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('user_name');
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->after('id');
        });
    }
}
