<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSmartCustomerTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('shop_customer_metrics')) {
            Schema::create('shop_customer_metrics', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->string('phone', 11);
                $table->unsignedInteger('recency_days')->default(0);
                $table->unsignedInteger('frequency')->default(0);
                $table->decimal('monetary', 15, 2)->default(0);
                $table->decimal('avg_days_between', 10, 2)->nullable();
                $table->timestamp('first_purchase_at')->nullable();
                $table->timestamp('last_purchase_at')->nullable();
                $table->unsignedInteger('purchase_count_30d')->default(0);
                $table->unsignedInteger('purchase_count_90d')->default(0);
                $table->decimal('monetary_30d', 15, 2)->default(0);
                $table->decimal('monetary_90d', 15, 2)->default(0);
                $table->decimal('avg_order_value', 15, 2)->default(0);
                $table->timestamp('computed_at')->nullable();
                $table->timestamps();

                $table->unique(['atelier_id', 'phone'], 'shop_customer_metrics_atelier_phone_uq');
                $table->index(['atelier_id', 'recency_days'], 'shop_customer_metrics_atelier_recency_idx');
            });
        }

        if (! Schema::hasTable('shop_segment_thresholds')) {
            Schema::create('shop_segment_thresholds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id')->unique();
                $table->string('metrics_window', 16)->default('all');
                $table->unsignedInteger('vip_max_recency_days')->default(30);
                $table->unsignedInteger('vip_min_frequency')->default(8);
                $table->decimal('vip_min_monetary', 15, 2)->default(15000000);
                $table->unsignedInteger('loyal_max_recency_days')->default(45);
                $table->unsignedInteger('loyal_min_frequency')->default(4);
                $table->unsignedInteger('new_max_days_since_first')->default(21);
                $table->unsignedInteger('new_max_frequency')->default(2);
                $table->unsignedInteger('growing_min_purchases_90d')->default(3);
                $table->decimal('at_risk_recency_multiplier', 5, 2)->default(1.5);
                $table->unsignedInteger('at_risk_min_recency_days')->default(35);
                $table->unsignedInteger('at_risk_min_frequency')->default(3);
                $table->unsignedInteger('inactive_min_recency_days')->default(60);
                $table->unsignedInteger('churned_min_recency_days')->default(120);
                $table->decimal('high_value_min_monetary', 15, 2)->default(10000000);
                $table->decimal('low_value_max_monetary', 15, 2)->default(1000000);
                $table->unsignedInteger('near_vip_frequency_gap')->default(2);
                $table->unsignedInteger('action_cooldown_days')->default(4);
                $table->decimal('winback_credit_amount', 15, 2)->default(100000);
                $table->decimal('winback_revenue_factor', 5, 2)->default(0.35);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shop_customer_segments')) {
            Schema::create('shop_customer_segments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->string('phone', 11);
                $table->string('primary_segment', 32);
                $table->json('tags')->nullable();
                $table->json('rfm_scores')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->unique(['atelier_id', 'phone'], 'shop_customer_segments_atelier_phone_uq');
                $table->index(['atelier_id', 'primary_segment'], 'shop_customer_segments_atelier_seg_idx');
            });
        }

        if (! Schema::hasTable('shop_smart_actions')) {
            Schema::create('shop_smart_actions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->string('phone', 11);
                $table->string('action_type', 64);
                $table->unsignedTinyInteger('priority')->default(50);
                $table->string('title', 191);
                $table->text('reason')->nullable();
                $table->json('payload')->nullable();
                $table->decimal('estimated_revenue', 15, 2)->default(0);
                $table->string('status', 24)->default('suggested');
                $table->string('source', 24)->default('system');
                $table->unsignedBigInteger('campaign_id')->nullable();
                $table->timestamp('suggested_send_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['atelier_id', 'status', 'priority'], 'shop_smart_actions_status_idx');
                $table->index(['atelier_id', 'phone', 'action_type'], 'shop_smart_actions_phone_type_idx');
            });
        }

        if (! Schema::hasTable('shop_campaigns')) {
            Schema::create('shop_campaigns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->string('name', 120);
                $table->string('status', 24)->default('draft');
                $table->string('trigger', 24)->default('manual');
                $table->unsignedInteger('cooldown_days')->default(4);
                $table->unsignedInteger('max_recipients_per_run')->nullable();
                $table->unsignedInteger('daily_sms_budget')->nullable();
                $table->boolean('require_manual_approve')->default(true);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->index(['atelier_id', 'status'], 'shop_campaigns_atelier_status_idx');
            });
        }

        if (! Schema::hasTable('shop_campaign_rules')) {
            Schema::create('shop_campaign_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('campaign_id');
                $table->json('conditions');
                $table->timestamps();

                $table->foreign('campaign_id')
                    ->references('id')
                    ->on('shop_campaigns')
                    ->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('shop_campaign_actions')) {
            Schema::create('shop_campaign_actions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('campaign_id');
                $table->unsignedSmallInteger('sort')->default(0);
                $table->string('type', 32);
                $table->json('config')->nullable();
                $table->timestamps();

                $table->foreign('campaign_id')
                    ->references('id')
                    ->on('shop_campaigns')
                    ->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('shop_campaign_runs')) {
            Schema::create('shop_campaign_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('campaign_id');
                $table->unsignedBigInteger('atelier_id');
                $table->string('trigger', 24)->default('manual');
                $table->unsignedInteger('matched_count')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->decimal('estimated_revenue', 15, 2)->default(0);
                $table->string('status', 24)->default('completed');
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->index(['atelier_id', 'campaign_id'], 'shop_campaign_runs_atelier_campaign_idx');
            });
        }

        if (! Schema::hasTable('shop_campaign_logs')) {
            Schema::create('shop_campaign_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('campaign_id');
                $table->unsignedBigInteger('run_id')->nullable();
                $table->unsignedBigInteger('atelier_id');
                $table->string('phone', 11);
                $table->string('status', 24);
                $table->string('skip_reason', 64)->nullable();
                $table->json('actions_result')->nullable();
                $table->timestamps();

                $table->index(['campaign_id', 'phone'], 'shop_campaign_logs_campaign_phone_idx');
                $table->index(['run_id'], 'shop_campaign_logs_run_idx');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('shop_campaign_logs');
        Schema::dropIfExists('shop_campaign_runs');
        Schema::dropIfExists('shop_campaign_actions');
        Schema::dropIfExists('shop_campaign_rules');
        Schema::dropIfExists('shop_campaigns');
        Schema::dropIfExists('shop_smart_actions');
        Schema::dropIfExists('shop_customer_segments');
        Schema::dropIfExists('shop_segment_thresholds');
        Schema::dropIfExists('shop_customer_metrics');
    }
}
