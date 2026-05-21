<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('card_amount', 15, 2)->default(0)->after('payment_type');
            $table->decimal('cash_amount', 15, 2)->default(0)->after('card_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['card_amount', 'cash_amount']);
        });
    }
};
