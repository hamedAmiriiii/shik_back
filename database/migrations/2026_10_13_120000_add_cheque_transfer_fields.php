<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddChequeTransferFields extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('cheques')) {
            return;
        }

        Schema::table('cheques', function (Blueprint $table) {
            if (! Schema::hasColumn('cheques', 'transferred_at')) {
                $table->timestamp('transferred_at')->nullable()->after('cleared_at');
            }
            if (! Schema::hasColumn('cheques', 'transferred_to_beneficiary_id')) {
                $table->unsignedBigInteger('transferred_to_beneficiary_id')->nullable()->after('transferred_at');
            }
            if (! Schema::hasColumn('cheques', 'transferred_to_invoice_id')) {
                $table->unsignedBigInteger('transferred_to_invoice_id')->nullable()->after('transferred_to_beneficiary_id');
            }
            if (! Schema::hasColumn('cheques', 'transferred_to_expense_id')) {
                $table->unsignedBigInteger('transferred_to_expense_id')->nullable()->after('transferred_to_invoice_id');
            }
            if (! Schema::hasColumn('cheques', 'transfer_document_payment_id')) {
                $table->unsignedBigInteger('transfer_document_payment_id')->nullable()->after('transferred_to_expense_id');
            }
            if (! Schema::hasColumn('cheques', 'transfer_note')) {
                $table->text('transfer_note')->nullable()->after('transfer_document_payment_id');
            }
        });

        try {
            DB::statement(
                "ALTER TABLE cheques MODIFY `status` ENUM('pending','cleared','cancelled','transferred') NOT NULL DEFAULT 'pending'"
            );
        } catch (\Throwable $e) {
            // درایور یا تعریف فعلی ممکن است متفاوت باشد
        }

        Schema::table('cheques', function (Blueprint $table) {
            if (Schema::hasTable('user_shiksho') && Schema::hasColumn('cheques', 'transferred_to_beneficiary_id')) {
                try {
                    $table->foreign('transferred_to_beneficiary_id')
                        ->references('id')->on('user_shiksho')->nullOnDelete();
                } catch (\Throwable $e) {
                }
            }
            if (Schema::hasTable('invoices') && Schema::hasColumn('cheques', 'transferred_to_invoice_id')) {
                try {
                    $table->foreign('transferred_to_invoice_id')
                        ->references('id')->on('invoices')->nullOnDelete();
                } catch (\Throwable $e) {
                }
            }
            if (Schema::hasTable('expenses') && Schema::hasColumn('cheques', 'transferred_to_expense_id')) {
                try {
                    $table->foreign('transferred_to_expense_id')
                        ->references('id')->on('expenses')->nullOnDelete();
                } catch (\Throwable $e) {
                }
            }
            if (Schema::hasTable('document_payments') && Schema::hasColumn('cheques', 'transfer_document_payment_id')) {
                try {
                    $table->foreign('transfer_document_payment_id')
                        ->references('id')->on('document_payments')->nullOnDelete();
                } catch (\Throwable $e) {
                }
            }
        });
    }

    public function down()
    {
        if (! Schema::hasTable('cheques')) {
            return;
        }

        Schema::table('cheques', function (Blueprint $table) {
            foreach ([
                'transferred_to_beneficiary_id',
                'transferred_to_invoice_id',
                'transferred_to_expense_id',
                'transfer_document_payment_id',
            ] as $column) {
                if (! Schema::hasColumn('cheques', $column)) {
                    continue;
                }
                try {
                    $table->dropForeign([$column]);
                } catch (\Throwable $e) {
                }
            }
        });

        Schema::table('cheques', function (Blueprint $table) {
            $drops = [];
            foreach ([
                'transferred_at',
                'transferred_to_beneficiary_id',
                'transferred_to_invoice_id',
                'transferred_to_expense_id',
                'transfer_document_payment_id',
                'transfer_note',
            ] as $column) {
                if (Schema::hasColumn('cheques', $column)) {
                    $drops[] = $column;
                }
            }
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });

        try {
            DB::statement(
                "ALTER TABLE cheques MODIFY `status` ENUM('pending','cleared','cancelled') NOT NULL DEFAULT 'pending'"
            );
        } catch (\Throwable $e) {
        }
    }
}
