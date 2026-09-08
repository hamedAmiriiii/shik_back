<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'daily_ticket_number')) {
                $table->unsignedInteger('daily_ticket_number')->nullable()->after('atelier_id');
            }
            if (! Schema::hasColumn('purchases', 'daily_ticket_date')) {
                $table->date('daily_ticket_date')->nullable()->after('daily_ticket_number');
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->index(['atelier_id', 'daily_ticket_date', 'daily_ticket_number'], 'purchases_atelier_daily_ticket_index');
        });

        Schema::create('shop_daily_ticket_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->date('ticket_date');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['atelier_id', 'ticket_date'], 'shop_daily_ticket_counters_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_daily_ticket_counters');
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_atelier_daily_ticket_index');
            $table->dropColumn(['daily_ticket_number', 'daily_ticket_date']);
        });
    }
};
