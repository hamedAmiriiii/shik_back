<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLowStockReportsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('low_stock_reports')) {
            return;
        }

        Schema::create('low_stock_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->date('calculated_date');
            $table->timestamp('calculated_at')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->unique('atelier_id', 'low_stock_reports_atelier_unique');
            $table->index(['atelier_id', 'calculated_date'], 'low_stock_reports_atelier_date_idx');
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('low_stock_reports');
    }
}
