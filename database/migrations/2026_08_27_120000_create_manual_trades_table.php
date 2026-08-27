<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateManualTradesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('manual_trades')) {
            return;
        }

        Schema::create('manual_trades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('shop_account_id')->nullable();
            $table->string('type', 16);
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('date');
            $table->string('user_name')->nullable();
            $table->timestamps();

            $table->index(['atelier_id', 'type', 'date']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
        });

        if (Schema::hasTable('shop_accounts')) {
            Schema::table('manual_trades', function (Blueprint $table) {
                $table->foreign('shop_account_id')->references('id')->on('shop_accounts')->nullOnDelete();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('manual_trades');
    }
}
