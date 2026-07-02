<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `purchases` MODIFY `payment_type` ENUM('cash', 'installment', 'debt') NOT NULL DEFAULT 'cash'");

        Schema::table('purchases', function (Blueprint $table) {
            $table->boolean('is_debt_settled')->default(false)->after('cash_amount');
            $table->timestamp('debt_settled_at')->nullable()->after('is_debt_settled');
            $table->decimal('debt_settled_card_amount', 15, 2)->default(0)->after('debt_settled_at');
            $table->decimal('debt_settled_cash_amount', 15, 2)->default(0)->after('debt_settled_card_amount');
            $table->string('debt_settlement_note', 500)->nullable()->after('debt_settled_cash_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn([
                'is_debt_settled',
                'debt_settled_at',
                'debt_settled_card_amount',
                'debt_settled_cash_amount',
                'debt_settlement_note',
            ]);
        });

        DB::statement("ALTER TABLE `purchases` MODIFY `payment_type` ENUM('cash', 'installment') NOT NULL DEFAULT 'cash'");
    }
};
