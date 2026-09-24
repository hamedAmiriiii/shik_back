<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProformaInvoicesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('proforma_invoices')) {
            return;
        }

        Schema::create('proforma_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->string('phone', 20)->nullable();
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->json('items');
            $table->timestamps();

            $table->index(['atelier_id', 'id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('proforma_invoices');
    }
}
