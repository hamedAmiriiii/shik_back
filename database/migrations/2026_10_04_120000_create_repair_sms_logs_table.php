<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRepairSmsLogsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('repair_sms_logs')) {
            return;
        }

        Schema::create('repair_sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->text('message');
            $table->string('sms_type', 32)->default('notify')->index();
            $table->unsignedSmallInteger('sms_parts')->default(0);
            $table->string('batch_id', 64)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->string('delivery_status', 32)->nullable()->index();
            $table->string('provider_datetime', 64)->nullable();
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('repair_sms_logs');
    }
}
