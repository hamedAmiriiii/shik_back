<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CreateShopReferralSystem extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 16)->nullable()->unique()->after('shop_staff_role');
            $table->string('referral_dashboard_token', 64)->nullable()->unique()->after('referral_code');
            $table->decimal('referral_balance', 15, 2)->default(0)->after('referral_dashboard_token');
        });

        Schema::table('ateliers', function (Blueprint $table) {
            $table->string('subscription_status', 16)->default('trial')->after('shop_access_suspended');
            $table->unsignedBigInteger('referred_by_user_id')->nullable()->after('subscription_status');
            $table->timestamp('paid_plan_activated_at')->nullable()->after('referred_by_user_id');

            $table->foreign('referred_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('subscription_status');
        });

        Schema::create('shop_referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_user_id');
            $table->unsignedBigInteger('referred_user_id');
            $table->unsignedBigInteger('referred_atelier_id')->nullable();
            $table->string('status', 32)->default('registered');
            $table->decimal('reward_amount', 15, 2)->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('plan_activated_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();

            $table->foreign('referrer_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('referred_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('referred_atelier_id')->references('id')->on('ateliers')->nullOnDelete();
            $table->unique('referred_atelier_id');
            $table->index(['referrer_user_id', 'status']);
        });

        $this->backfillReferralCodes();
    }

    public function down()
    {
        Schema::dropIfExists('shop_referrals');

        Schema::table('ateliers', function (Blueprint $table) {
            $table->dropForeign(['referred_by_user_id']);
            $table->dropColumn(['subscription_status', 'referred_by_user_id', 'paid_plan_activated_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['referral_code', 'referral_dashboard_token', 'referral_balance']);
        });
    }

    protected function backfillReferralCodes(): void
    {
        $users = DB::table('users')
            ->whereNull('referral_code')
            ->orderBy('id')
            ->get(['id']);

        foreach ($users as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'referral_code' => $this->generateUniqueReferralCode(),
                'referral_dashboard_token' => Str::random(48),
            ]);
        }
    }

    protected function generateUniqueReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (DB::table('users')->where('referral_code', $code)->exists());

        return $code;
    }
}
