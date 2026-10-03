<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRepairTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('repair_users')) {
            Schema::create('repair_users', function (Blueprint $table) {
                $table->id();
                $table->string('role', 20)->default('customer');
                $table->string('name')->nullable();
                $table->string('phone', 20)->unique();
                $table->string('specialty')->nullable();
                $table->decimal('labor_share_percent', 5, 2)->default(0);
                $table->string('card_number', 32)->nullable();
                $table->string('address', 1000)->nullable();
                $table->string('notes', 2000)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_login_at')->nullable();
                $table->timestamps();

                $table->index(['role', 'is_active']);
            });
        }

        if (! Schema::hasTable('repair_requests')) {
            Schema::create('repair_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('technician_id')->nullable();
                $table->string('category')->nullable();
                $table->text('description');
                $table->string('address', 1000);
                $table->string('contact_name')->nullable();
                $table->string('contact_phone', 20);
                $table->string('preferred_time')->nullable();
                $table->string('status', 30)->default('pending');
                $table->string('admin_note', 2000)->nullable();

                $table->unsignedBigInteger('labor_amount')->default(0);
                $table->unsignedBigInteger('parts_amount')->default(0);
                $table->unsignedBigInteger('total_amount')->default(0);
                $table->string('cost_description', 2000)->nullable();
                $table->decimal('share_percent', 5, 2)->default(0);
                $table->unsignedBigInteger('technician_share')->default(0);
                $table->unsignedBigInteger('platform_share')->default(0);

                $table->string('payment_method', 20)->nullable();
                $table->string('payment_ref')->nullable();
                $table->unsignedBigInteger('gateway_payment_id')->nullable();
                $table->string('receipt_path')->nullable();
                $table->timestamp('receipt_submitted_at')->nullable();
                $table->string('receipt_reject_reason', 1000)->nullable();

                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('invoiced_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('canceled_at')->nullable();
                $table->string('cancel_reason', 1000)->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index(['customer_id', 'created_at']);
                $table->index(['technician_id', 'status']);
            });
        }

        if (! Schema::hasTable('repair_payouts')) {
            Schema::create('repair_payouts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('technician_id');
                $table->unsignedBigInteger('amount');
                $table->date('paid_on');
                $table->string('method', 50)->nullable();
                $table->string('note', 1000)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['technician_id', 'paid_on']);
            });
        }

        if (! Schema::hasTable('repair_settings')) {
            Schema::create('repair_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100)->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('repair_settings');
        Schema::dropIfExists('repair_payouts');
        Schema::dropIfExists('repair_requests');
        Schema::dropIfExists('repair_users');
    }
}
