<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSubscriptionPricesToAteliersTable extends Migration
{
    public function up()
    {
        Schema::table('ateliers', function (Blueprint $table) {
            if (! Schema::hasColumn('ateliers', 'subscription_current_price_rial')) {
                $table->unsignedBigInteger('subscription_current_price_rial')->nullable()->after('paid_plan_activated_at');
            }
            if (! Schema::hasColumn('ateliers', 'subscription_renewal_price_rial')) {
                $table->unsignedBigInteger('subscription_renewal_price_rial')->nullable()->after('subscription_current_price_rial');
            }
            if (! Schema::hasColumn('ateliers', 'subscription_renewal_days')) {
                $table->unsignedInteger('subscription_renewal_days')->nullable()->after('subscription_renewal_price_rial');
            }
        });
    }

    public function down()
    {
        Schema::table('ateliers', function (Blueprint $table) {
            if (Schema::hasColumn('ateliers', 'subscription_renewal_days')) {
                $table->dropColumn('subscription_renewal_days');
            }
            if (Schema::hasColumn('ateliers', 'subscription_renewal_price_rial')) {
                $table->dropColumn('subscription_renewal_price_rial');
            }
            if (Schema::hasColumn('ateliers', 'subscription_current_price_rial')) {
                $table->dropColumn('subscription_current_price_rial');
            }
        });
    }
}
