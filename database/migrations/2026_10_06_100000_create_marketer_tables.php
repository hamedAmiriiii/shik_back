<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMarketerTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('marketers')) {
            Schema::create('marketers', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('phone', 11)->unique();
                $table->string('code', 16)->unique();
                $table->decimal('commission_percent', 5, 2)->nullable();
                $table->string('card_number', 32)->nullable();
                $table->string('sheba', 32)->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('admin_note')->nullable();
                $table->timestamp('last_login_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketer_tokens')) {
            Schema::create('marketer_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('marketer_id')->constrained('marketers')->cascadeOnDelete();
                $table->string('token_hash', 64)->unique();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketer_visits')) {
            Schema::create('marketer_visits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('marketer_id')->constrained('marketers')->cascadeOnDelete();
                $table->string('visitor_id', 64);
                $table->string('ip', 45)->nullable();
                $table->string('user_agent')->nullable();
                $table->string('landing_path')->nullable();
                $table->timestamps();
                $table->index(['marketer_id', 'visitor_id']);
            });
        }

        if (! Schema::hasTable('marketer_referrals')) {
            Schema::create('marketer_referrals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('marketer_id')->constrained('marketers')->cascadeOnDelete();
                $table->unsignedBigInteger('atelier_id')->unique();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('visitor_id', 64)->nullable();
                $table->timestamp('first_visit_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketer_commissions')) {
            Schema::create('marketer_commissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('marketer_id')->constrained('marketers')->cascadeOnDelete();
                $table->unsignedBigInteger('marketer_referral_id')->index();
                $table->unsignedBigInteger('atelier_id')->index();
                $table->unsignedBigInteger('gateway_payment_id')->unique();
                $table->unsignedBigInteger('purchase_amount_toman');
                $table->decimal('percent', 5, 2);
                $table->unsignedBigInteger('commission_toman');
                $table->string('description')->nullable();
                $table->timestamp('purchased_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketer_payouts')) {
            Schema::create('marketer_payouts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('marketer_id')->constrained('marketers')->cascadeOnDelete();
                $table->unsignedBigInteger('amount_toman');
                $table->string('note')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketing_settings')) {
            Schema::create('marketing_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 64)->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('marketing_settings');
        Schema::dropIfExists('marketer_payouts');
        Schema::dropIfExists('marketer_commissions');
        Schema::dropIfExists('marketer_referrals');
        Schema::dropIfExists('marketer_visits');
        Schema::dropIfExists('marketer_tokens');
        Schema::dropIfExists('marketers');
    }
}
