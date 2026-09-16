<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShopCustomerGroupsTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('shop_customer_groups')) {
            Schema::create('shop_customer_groups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->string('name', 120);
                $table->timestamps();

                $table->index(['atelier_id', 'name'], 'shop_customer_groups_atelier_name_idx');
            });
        }

        if (! Schema::hasTable('shop_customer_group_members')) {
            Schema::create('shop_customer_group_members', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('group_id');
                $table->string('phone', 11);
                $table->timestamps();

                $table->unique(['group_id', 'phone'], 'shop_customer_group_members_group_phone_unique');
                $table->foreign('group_id')
                    ->references('id')
                    ->on('shop_customer_groups')
                    ->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('shop_customer_group_members');
        Schema::dropIfExists('shop_customer_groups');
    }
}
