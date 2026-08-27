<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAgencyRequestsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('agency_requests')) {
            return;
        }

        Schema::create('agency_requests', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('state_name')->nullable()->comment('نام استان در لحظه ثبت');
            $table->string('city_name')->nullable()->comment('نام شهر در لحظه ثبت');
            $table->string('phone', 20);
            $table->string('education', 64)->comment('مدرک تحصیلی');
            $table->string('status', 32)->default('pending')->comment('pending | contacted | approved | rejected');
            $table->text('admin_note')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index('phone');
            $table->index(['status', 'created_at']);
            $table->index('state_id');
            $table->index('city_id');
        });

        // کلید خارجی فقط اگر نوع ستون‌های مرجع سازگار باشد؛ در غیر این صورت جدول بدون FK کار می‌کند.
        try {
            if (Schema::hasTable('states') && Schema::hasTable('cities')) {
                Schema::table('agency_requests', function (Blueprint $table) {
                    $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
                    $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
                });
            }
        } catch (\Throwable $e) {
            // بدون FK ادامه می‌دهیم
        }
    }

    public function down()
    {
        Schema::dropIfExists('agency_requests');
    }
}
