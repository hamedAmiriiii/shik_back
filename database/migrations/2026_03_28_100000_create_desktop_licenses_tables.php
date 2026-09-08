<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDesktopLicensesTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('desktop_licenses')) {
            Schema::create('desktop_licenses', function (Blueprint $table) {
                $table->id();
                $table->string('license_key', 64)->unique();
                $table->string('customer_name')->nullable();
                $table->string('customer_phone', 32)->nullable();
                $table->string('customer_email')->nullable();
                $table->unsignedInteger('max_devices')->default(1);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->string('status', 32)->default('active'); // active|revoked|expired
                $table->json('features')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('desktop_license_activations')) {
            Schema::create('desktop_license_activations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('desktop_license_id');
                $table->string('machine_id', 128);
                $table->string('machine_label')->nullable();
                $table->string('app_version', 32)->nullable();
                $table->string('platform', 32)->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->unique(['desktop_license_id', 'machine_id'], 'desktop_lic_machine_unique');
                $table->foreign('desktop_license_id', 'desktop_lic_act_fk')
                    ->references('id')
                    ->on('desktop_licenses')
                    ->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('desktop_license_activations');
        Schema::dropIfExists('desktop_licenses');
    }
}
