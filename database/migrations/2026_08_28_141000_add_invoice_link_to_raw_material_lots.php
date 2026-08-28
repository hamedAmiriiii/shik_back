<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddInvoiceLinkToRawMaterialLots extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('raw_material_lots')) {
            return;
        }

        Schema::table('raw_material_lots', function (Blueprint $table) {
            if (! Schema::hasColumn('raw_material_lots', 'invoice_id')) {
                $table->unsignedBigInteger('invoice_id')->nullable()->after('note');
                $table->index('invoice_id');
            }
            if (! Schema::hasColumn('raw_material_lots', 'invoice_item_id')) {
                $table->unsignedBigInteger('invoice_item_id')->nullable()->after('invoice_id');
                $table->index('invoice_item_id');
            }
        });

        Schema::table('raw_material_lots', function (Blueprint $table) {
            if (Schema::hasTable('invoices') && Schema::hasColumn('raw_material_lots', 'invoice_id')) {
                $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            }
            if (Schema::hasTable('invoice_items') && Schema::hasColumn('raw_material_lots', 'invoice_item_id')) {
                $table->foreign('invoice_item_id')->references('id')->on('invoice_items')->nullOnDelete();
            }
        });
    }

    public function down()
    {
        if (! Schema::hasTable('raw_material_lots')) {
            return;
        }

        Schema::table('raw_material_lots', function (Blueprint $table) {
            foreach (['invoice_item_id', 'invoice_id'] as $col) {
                if (Schema::hasColumn('raw_material_lots', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
}
