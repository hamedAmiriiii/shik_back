<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAccountingAuditLogsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('accounting_audit_logs')) {
            return;
        }

        Schema::create('accounting_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 32);
            $table->unsignedBigInteger('voucher_id')->nullable();
            $table->unsignedBigInteger('related_voucher_id')->nullable();
            $table->date('closed_through')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->text('payload')->nullable();
            $table->timestamps();

            $table->index(['atelier_id', 'created_at']);
            $table->index('voucher_id');
            $table->index('related_voucher_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('accounting_audit_logs');
    }
}
