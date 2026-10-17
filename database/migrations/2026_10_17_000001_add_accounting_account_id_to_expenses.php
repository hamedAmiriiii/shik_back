<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expenses') || Schema::hasColumn('expenses', 'accounting_account_id')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('accounting_account_id')->nullable()->after('atelier_id');
            $table->index('accounting_account_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('expenses') || ! Schema::hasColumn('expenses', 'accounting_account_id')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['accounting_account_id']);
            $table->dropColumn('accounting_account_id');
        });
    }
};
