<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDiscountToInvoiceItems extends Migration
{
    public function up()
    {
        if (Schema::hasTable('invoice_items') && ! Schema::hasColumn('invoice_items', 'discount_amount')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->decimal('discount_amount', 15, 2)->default(0)->after('vat_amount');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('invoice_items') && Schema::hasColumn('invoice_items', 'discount_amount')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropColumn('discount_amount');
            });
        }
    }
}
