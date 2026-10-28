<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAccountingPostingMapsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('accounting_posting_maps')) {
            return;
        }

        Schema::create('accounting_posting_maps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id')->index();
            $table->string('event_key', 64);
            $table->unsignedBigInteger('account_id')->index();
            $table->timestamps();

            $table->unique(['atelier_id', 'event_key']);
        });

        if (Schema::hasTable('ateliers')) {
            try {
                Schema::table('accounting_posting_maps', function (Blueprint $table) {
                    $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
                });
            } catch (\Throwable $e) {
            }
        }
        if (Schema::hasTable('accounting_accounts')) {
            try {
                Schema::table('accounting_posting_maps', function (Blueprint $table) {
                    $table->foreign('account_id')->references('id')->on('accounting_accounts')->restrictOnDelete();
                });
            } catch (\Throwable $e) {
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('accounting_posting_maps');
    }
}
