<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('proforma_invoices') || Schema::hasColumn('proforma_invoices', 'customer_name')) {
            return;
        }

        Schema::table('proforma_invoices', function (Blueprint $table) {
            $table->string('customer_name', 255)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('proforma_invoices') || ! Schema::hasColumn('proforma_invoices', 'customer_name')) {
            return;
        }

        Schema::table('proforma_invoices', function (Blueprint $table) {
            $table->dropColumn('customer_name');
        });
    }
};
