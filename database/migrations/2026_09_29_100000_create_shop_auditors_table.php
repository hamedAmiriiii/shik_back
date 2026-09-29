<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShopAuditorsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('shop_auditors')) {
            return;
        }

        Schema::create('shop_auditors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('user_id');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('permissions')->nullable();
            $table->string('note', 2000)->nullable();
            $table->timestamps();

            $table->unique(['atelier_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('shop_auditors');
    }
}
