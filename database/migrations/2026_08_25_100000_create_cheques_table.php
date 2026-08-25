<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChequesTable extends Migration
{
    public function up()
    {
        // اگر جدول قبلی/جدید از قبل هست، ارتقا در migration بعدی انجام می‌شود
        if (Schema::hasTable('cheques') || Schema::hasTable('issued_cheques')) {
            return;
        }

        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->enum('type', ['issued', 'received'])->comment('issued=صادره | received=دریافتی');
            $table->string('cheque_number', 64)->comment('شماره چک');
            $table->string('bank_name', 255)->nullable()->comment('نام بانک');
            $table->string('payee', 255)->nullable()->comment('طرف حساب (در وجه / صادرکننده)');
            $table->decimal('amount', 15, 2);
            $table->date('issue_date')->nullable()->comment('تاریخ صدور');
            $table->date('due_date')->comment('تاریخ سررسید');
            $table->string('title', 255)->nullable();
            $table->enum('expense_type', ['جاری', 'سرمایه'])->default('جاری')
                ->comment('فقط برای چک صادره هنگام ثبت هزینه');
            $table->enum('status', ['pending', 'cleared', 'cancelled'])->default('pending')
                ->comment('pending=در انتظار | cleared=وصول‌شده | cancelled=باطل');
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->unsignedBigInteger('income_id')->nullable();
            $table->string('user_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->index(['atelier_id', 'type', 'status']);
            $table->index(['atelier_id', 'due_date']);
            $table->index('status');
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            $table->foreign('expense_id')->references('id')->on('expenses')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cheques');
    }
}
