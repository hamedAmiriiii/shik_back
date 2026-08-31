<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAccountingVouchers extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('accounting_vouchers')) {
            Schema::create('accounting_vouchers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedInteger('number');
                $table->date('date');
                $table->string('description')->nullable();
                $table->string('source_type', 64);
                $table->unsignedBigInteger('source_id');
                $table->string('status', 16)->default('posted');
                $table->unsignedBigInteger('reverses_voucher_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('active_source_key', 96)->nullable()
                    ->comment('فقط سند posted غیربرگشتی: source_type:source_id');
                $table->timestamps();

                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
                $table->foreign('reverses_voucher_id', 'accounting_vouchers_reverses_fk')
                    ->references('id')
                    ->on('accounting_vouchers')
                    ->nullOnDelete();
                $table->unique(['atelier_id', 'number'], 'accounting_vouchers_atelier_number_unique');
                $table->unique(['atelier_id', 'active_source_key'], 'accounting_vouchers_active_source_unique');
                $table->index(['atelier_id', 'date']);
                $table->index(['atelier_id', 'source_type', 'source_id'], 'accounting_vouchers_source_index');
            });
        }

        if (! Schema::hasTable('accounting_lines')) {
            Schema::create('accounting_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('voucher_id');
                $table->unsignedBigInteger('account_id');
                $table->decimal('debit', 15, 2)->default(0);
                $table->decimal('credit', 15, 2)->default(0);
                $table->string('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->foreign('voucher_id', 'accounting_lines_voucher_fk')
                    ->references('id')
                    ->on('accounting_vouchers')
                    ->cascadeOnDelete();
                $table->foreign('account_id', 'accounting_lines_account_fk')
                    ->references('id')
                    ->on('accounting_accounts')
                    ->restrictOnDelete();
                $table->index(['account_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('accounting_lines');
        Schema::dropIfExists('accounting_vouchers');
    }
}
