<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShopServicesAndTableServiceRequests extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('shop_services')) {
            Schema::create('shop_services', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->string('name', 120);
                $table->string('description', 500)->nullable();
                $table->string('icon_key', 40)->default('other');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['atelier_id', 'is_active', 'sort_order']);
                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('table_service_requests')) {
            Schema::create('table_service_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedBigInteger('shop_table_id');
                $table->unsignedBigInteger('shop_service_id')->nullable();
                $table->string('service_name', 120);
                $table->string('icon_key', 40)->default('other');
                $table->string('phone', 11)->nullable();
                $table->string('note', 500)->nullable();
                $table->string('status', 20)->default('pending');
                $table->timestamp('done_at')->nullable();
                $table->timestamps();

                $table->index(['atelier_id', 'status']);
                $table->index(['shop_table_id', 'status']);
                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
                $table->foreign('shop_table_id')->references('id')->on('shop_tables')->cascadeOnDelete();
                $table->foreign('shop_service_id')->references('id')->on('shop_services')->nullOnDelete();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('table_service_requests');
        Schema::dropIfExists('shop_services');
    }
}
