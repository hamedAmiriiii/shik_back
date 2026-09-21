<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddExpiryToUserCreditGrants extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('user_credit_grants')) {
            return;
        }

        Schema::table('user_credit_grants', function (Blueprint $table) {
            if (! Schema::hasColumn('user_credit_grants', 'remaining')) {
                $table->decimal('remaining', 15, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('user_credit_grants', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('remaining');
            }
            if (! Schema::hasColumn('user_credit_grants', 'campaign_id')) {
                $table->unsignedBigInteger('campaign_id')->nullable()->after('purchase_id');
            }
        });
    }

    public function down()
    {
        if (! Schema::hasTable('user_credit_grants')) {
            return;
        }

        Schema::table('user_credit_grants', function (Blueprint $table) {
            $cols = [];
            foreach (['campaign_id', 'expires_at', 'remaining'] as $col) {
                if (Schema::hasColumn('user_credit_grants', $col)) {
                    $cols[] = $col;
                }
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
}
