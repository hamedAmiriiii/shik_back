<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBeneficiaryToInvoicesAndExpenses extends Migration
{
    public function up()
    {
        $this->addBeneficiaryColumn('invoices');
        $this->addBeneficiaryColumn('expenses');
    }

    public function down()
    {
        $this->dropBeneficiaryColumn('invoices');
        $this->dropBeneficiaryColumn('expenses');
    }

    protected function addBeneficiaryColumn(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'beneficiary_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->unsignedBigInteger('beneficiary_id')->nullable()->after('atelier_id');
            $table->index('beneficiary_id');
        });

        if (Schema::hasTable('user_shiksho')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('beneficiary_id')
                    ->references('id')
                    ->on('user_shiksho')
                    ->nullOnDelete();
            });
        }
    }

    protected function dropBeneficiaryColumn(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'beneficiary_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            try {
                $table->dropForeign($tableName.'_beneficiary_id_foreign');
            } catch (\Throwable $e) {
                //
            }
            $table->dropColumn('beneficiary_id');
        });
    }
}
