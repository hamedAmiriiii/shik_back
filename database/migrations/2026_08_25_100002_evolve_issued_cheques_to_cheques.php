<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اگر قبلاً جدول issued_cheques ساخته شده، به cheques ارتقا می‌دهد
 * و ستون‌های type / income_id را اضافه می‌کند.
 */
class EvolveIssuedChequesToCheques extends Migration
{
    public function up()
    {
        if (Schema::hasTable('issued_cheques') && !Schema::hasTable('cheques')) {
            Schema::rename('issued_cheques', 'cheques');
        }

        if (!Schema::hasTable('cheques')) {
            return;
        }

        Schema::table('cheques', function (Blueprint $table) {
            if (!Schema::hasColumn('cheques', 'type')) {
                $table->enum('type', ['issued', 'received'])->default('issued')
                    ->after('atelier_id')
                    ->comment('issued=صادره | received=دریافتی');
            }
            if (!Schema::hasColumn('cheques', 'income_id')) {
                $table->unsignedBigInteger('income_id')->nullable()->after('expense_id');
            }
        });

        if (Schema::hasTable('incomes') && Schema::hasColumn('cheques', 'income_id')) {
            try {
                Schema::table('cheques', function (Blueprint $table) {
                    $table->foreign('income_id')->references('id')->on('incomes')->nullOnDelete();
                });
            } catch (\Throwable $e) {
                // FK ممکن است از قبل وجود داشته باشد
            }
        }
    }

    public function down()
    {
        if (!Schema::hasTable('cheques') || Schema::hasTable('issued_cheques')) {
            return;
        }

        Schema::table('cheques', function (Blueprint $table) {
            if (Schema::hasColumn('cheques', 'income_id')) {
                try {
                    $table->dropForeign(['income_id']);
                } catch (\Throwable $e) {
                    // ignore
                }
                $table->dropColumn('income_id');
            }
            if (Schema::hasColumn('cheques', 'type')) {
                $table->dropColumn('type');
            }
        });

        Schema::rename('cheques', 'issued_cheques');
    }
}
