<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserCreditGrantsTable extends Migration
{
    public function up()
    {
        Schema::create('user_credit_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->string('phone', 11);
            $table->enum('credit_type', ['regular', 'installment']);
            $table->decimal('amount', 15, 2);
            $table->string('source', 32)->default('manual');
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->timestamps();

            $table->index(['atelier_id', 'created_at']);
            $table->index(['atelier_id', 'phone']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_credit_grants');
    }
}
