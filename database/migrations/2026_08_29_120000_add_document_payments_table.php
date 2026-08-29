<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDocumentPaymentsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('document_payments')) {
            return;
        }

        Schema::create('document_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->string('method', 16);
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedBigInteger('shop_account_id')->nullable();
            $table->unsignedBigInteger('cheque_id')->nullable();
            $table->boolean('settled')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('atelier_id');
            $table->index('invoice_id');
            $table->index('expense_id');
            $table->index('shop_account_id');
            $table->index('cheque_id');
        });

        Schema::table('document_payments', function (Blueprint $table) {
            if (Schema::hasTable('invoices')) {
                $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            }
            if (Schema::hasTable('expenses')) {
                $table->foreign('expense_id')->references('id')->on('expenses')->cascadeOnDelete();
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('document_payments');
    }
}
