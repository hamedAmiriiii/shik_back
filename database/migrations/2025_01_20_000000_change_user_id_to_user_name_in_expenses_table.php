<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeUserIdToUserNameInExpensesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('expenses', function (Blueprint $table) {
            // حذف foreign key constraint
            $table->dropForeign(['user_id']);
            // حذف ستون user_id
            $table->dropColumn('user_id');
            // اضافه کردن ستون user_name
            $table->string('user_name')->after('id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('user_name');
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->after('id');
        });
    }
}
