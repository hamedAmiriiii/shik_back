<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCreditSourceToExpenses extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'credit_source')) {
                $table->string('credit_source', 32)->nullable()->after('title');
            }
            if (! Schema::hasColumn('expenses', 'credit_source_id')) {
                $table->unsignedBigInteger('credit_source_id')->nullable()->after('credit_source');
            }
        });

        try {
            Schema::table('expenses', function (Blueprint $table) {
                $table->index(
                    ['atelier_id', 'credit_source', 'credit_source_id'],
                    'expenses_credit_source_index'
                );
            });
        } catch (\Throwable $e) {
            //
        }
    }

    public function down()
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            try {
                $table->dropIndex('expenses_credit_source_index');
            } catch (\Throwable $e) {
                //
            }
            $cols = [];
            if (Schema::hasColumn('expenses', 'credit_source_id')) {
                $cols[] = 'credit_source_id';
            }
            if (Schema::hasColumn('expenses', 'credit_source')) {
                $cols[] = 'credit_source';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
}
