<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'purchased_on')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->date('purchased_on')->nullable()->after('purchase_price');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'purchased_on')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('purchased_on');
            });
        }
    }
};
