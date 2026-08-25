<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateIncomesTable extends Migration
{
    public function up()
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id')->nullable();
            $table->string('user_name');
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('title');
            $table->timestamps();

            $table->index(['atelier_id', 'date']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });

        if (Schema::hasTable('cheques') && Schema::hasColumn('cheques', 'income_id')) {
            Schema::table('cheques', function (Blueprint $table) {
                $table->foreign('income_id')->references('id')->on('incomes')->nullOnDelete();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('incomes');
    }
}
