<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAuditFieldsToShopAccountBalanceAdjustments extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('shop_account_balance_adjustments')) {
            return;
        }

        Schema::table('shop_account_balance_adjustments', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_account_balance_adjustments', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('shop_account_id');
            }
            if (! Schema::hasColumn('shop_account_balance_adjustments', 'user_name')) {
                $table->string('user_name', 120)->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('shop_account_balance_adjustments', 'balance_before')) {
                $table->decimal('balance_before', 15, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('shop_account_balance_adjustments', 'balance_after')) {
                $table->decimal('balance_after', 15, 2)->nullable()->after('balance_before');
            }
        });
    }

    public function down()
    {
        if (! Schema::hasTable('shop_account_balance_adjustments')) {
            return;
        }

        Schema::table('shop_account_balance_adjustments', function (Blueprint $table) {
            foreach (['user_id', 'user_name', 'balance_before', 'balance_after'] as $column) {
                if (Schema::hasColumn('shop_account_balance_adjustments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
