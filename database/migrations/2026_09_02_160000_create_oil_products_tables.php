<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOilProductsTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('oil_products')) {
            Schema::create('oil_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->string('kind', 32);
                $table->string('name', 120);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['atelier_id', 'kind', 'is_active']);
                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('oil_visit_items')) {
            Schema::create('oil_visit_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('oil_visit_id');
                $table->unsignedBigInteger('oil_product_id')->nullable();
                $table->string('kind', 32);
                $table->string('product_name', 120);
                $table->timestamps();

                $table->unique(['oil_visit_id', 'kind']);
                $table->foreign('oil_visit_id')->references('id')->on('oil_visits')->cascadeOnDelete();
                $table->foreign('oil_product_id')->references('id')->on('oil_products')->onDelete('set null');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('oil_visit_items');
        Schema::dropIfExists('oil_products');
    }
}
