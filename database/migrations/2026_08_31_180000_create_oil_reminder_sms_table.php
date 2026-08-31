<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOilReminderSmsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('oil_reminder_sms')) {
            return;
        }

        Schema::create('oil_reminder_sms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('oil_visit_id');
            $table->string('plate', 32);
            $table->string('plate_display', 64);
            $table->string('phone', 11);
            $table->unsignedInteger('next_km');
            $table->date('estimated_due_on')->nullable();
            $table->smallInteger('days_until_due')->nullable();
            $table->text('message');
            $table->boolean('sms_sent')->default(false);
            $table->string('sms_error')->nullable();
            $table->timestamps();

            $table->unique('oil_visit_id');
            $table->index(['atelier_id', 'created_at']);
            $table->index(['atelier_id', 'phone']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            $table->foreign('oil_visit_id')->references('id')->on('oil_visits')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('oil_reminder_sms');
    }
}
