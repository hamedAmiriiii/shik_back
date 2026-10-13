<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBeneficiaryAdvancesTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('beneficiary_advances')) {
            Schema::create('beneficiary_advances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedBigInteger('beneficiary_id');
                $table->decimal('amount', 15, 2);
                $table->decimal('remaining', 15, 2);
                $table->string('source', 32)->default('cheque_transfer');
                $table->unsignedBigInteger('cheque_id')->nullable();
                $table->unsignedBigInteger('settled_invoice_id')->nullable();
                $table->unsignedBigInteger('settled_expense_id')->nullable();
                $table->string('status', 16)->default('open'); // open|partial|used|reversed
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['atelier_id', 'beneficiary_id', 'status']);
                $table->index('cheque_id');
            });
        }

        if (! Schema::hasTable('beneficiary_advance_applications')) {
            Schema::create('beneficiary_advance_applications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedBigInteger('advance_id');
                $table->unsignedBigInteger('invoice_id')->nullable();
                $table->unsignedBigInteger('expense_id')->nullable();
                $table->unsignedBigInteger('document_payment_id')->nullable();
                $table->decimal('amount', 15, 2);
                $table->timestamps();

                $table->index('advance_id');
                $table->index('invoice_id');
                $table->index('expense_id');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('beneficiary_advance_applications');
        Schema::dropIfExists('beneficiary_advances');
    }
}
