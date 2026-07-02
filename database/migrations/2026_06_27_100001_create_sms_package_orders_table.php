<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSmsPackageOrdersTable extends Migration
{
    public function up()
    {
        Schema::create('sms_package_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('sms_package_id');
            $table->unsignedInteger('sms_count');
            $table->unsignedBigInteger('price_rial')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('admin_note', 500)->nullable();
            $table->timestamps();

            $table->index(['atelier_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->foreign('sms_package_id')->references('id')->on('sms_packages');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sms_package_orders');
    }
}
