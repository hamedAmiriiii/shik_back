<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateConsumableMaterialsTables extends Migration
{
    public function up()
    {
        Schema::create('consumable_materials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->string('name');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['atelier_id', 'name'], 'consumable_materials_atelier_name_unique');
            $table->index(['atelier_id', 'name']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
        });

        Schema::create('consumable_material_lots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('consumable_material_id');
            $table->decimal('quantity', 12, 3);
            $table->decimal('remaining', 12, 3);
            $table->decimal('unit_cost', 15, 2);
            $table->timestamp('purchased_at')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('invoice_item_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['consumable_material_id', 'purchased_at', 'id'], 'consumable_lots_fifo');
            $table->index(['atelier_id', 'consumable_material_id']);
            $table->index('invoice_id');
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            $table->foreign('consumable_material_id')->references('id')->on('consumable_materials')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('invoice_item_id')->references('id')->on('invoice_items')->nullOnDelete();
        });

        Schema::create('consumable_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('consumable_material_id');
            $table->decimal('quantity', 12, 3);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['atelier_id', 'withdrawn_at'], 'consumable_withdrawals_atelier_date');
            $table->index('consumable_material_id');
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            $table->foreign('consumable_material_id')->references('id')->on('consumable_materials')->onDelete('restrict');
        });

        Schema::create('consumable_withdrawal_slices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consumable_withdrawal_id');
            $table->unsignedBigInteger('consumable_material_lot_id');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('cost', 15, 2);
            $table->timestamps();

            $table->index('consumable_withdrawal_id', 'cws_withdrawal_idx');
            $table->index('consumable_material_lot_id', 'cws_lot_idx');
            $table->foreign('consumable_withdrawal_id', 'cws_withdrawal_fk')
                ->references('id')->on('consumable_withdrawals')->cascadeOnDelete();
            $table->foreign('consumable_material_lot_id', 'cws_lot_fk')
                ->references('id')->on('consumable_material_lots')->onDelete('restrict');
        });

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                if (! Schema::hasColumn('invoice_items', 'vat_mode')) {
                    $table->string('vat_mode', 16)->default('none')->after('total');
                }
                if (! Schema::hasColumn('invoice_items', 'vat_percent')) {
                    $table->decimal('vat_percent', 8, 2)->nullable()->after('vat_mode');
                }
                if (! Schema::hasColumn('invoice_items', 'vat_amount')) {
                    $table->decimal('vat_amount', 15, 2)->default(0)->after('vat_percent');
                }
            });
        }

        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'discount_amount')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->decimal('discount_amount', 15, 2)->default(0)->after('vat_amount');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('consumable_withdrawal_slices');
        Schema::dropIfExists('consumable_withdrawals');
        Schema::dropIfExists('consumable_material_lots');
        Schema::dropIfExists('consumable_materials');

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                foreach (['vat_amount', 'vat_percent', 'vat_mode'] as $column) {
                    if (Schema::hasColumn('invoice_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'discount_amount')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('discount_amount');
            });
        }
    }
}
