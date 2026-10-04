<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_debt_payments')
            || Schema::hasColumn('purchase_debt_payments', 'shop_account_id')
        ) {
            return;
        }

        Schema::table('purchase_debt_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_account_id')->nullable()->after('purchase_id');
            $table->index('shop_account_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_debt_payments')
            || ! Schema::hasColumn('purchase_debt_payments', 'shop_account_id')
        ) {
            return;
        }

        Schema::table('purchase_debt_payments', function (Blueprint $table) {
            $table->dropIndex(['shop_account_id']);
            $table->dropColumn('shop_account_id');
        });
    }
};
