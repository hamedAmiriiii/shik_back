<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddInvoiceItemsAndImage extends Migration
{
    public function up()
    {
        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'image_path')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('image_path', 255)->nullable()->after('description');
            });
        }

        if (! Schema::hasTable('invoice_items')) {
            Schema::create('invoice_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('invoice_id');
                $table->string('title');
                $table->decimal('unit_price', 15, 2)->default(0);
                $table->decimal('quantity', 12, 3)->default(1);
                $table->decimal('total', 15, 2)->default(0);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index('invoice_id');
                $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('invoice_items');

        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'image_path')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('image_path');
            });
        }
    }
}
