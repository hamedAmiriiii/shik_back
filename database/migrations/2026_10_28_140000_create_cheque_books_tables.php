<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChequeBooksTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('cheque_books')) {
            Schema::create('cheque_books', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id')->index();
                $table->string('title', 255)->nullable();
                $table->string('bank_name', 255)->nullable();
                $table->unsignedBigInteger('shop_account_id')->nullable()->index();
                $table->unsignedBigInteger('start_number');
                $table->unsignedBigInteger('end_number');
                $table->unsignedInteger('leaf_count');
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['atelier_id', 'id']);
            });
        }

        if (! Schema::hasTable('cheque_book_leaves')) {
            Schema::create('cheque_book_leaves', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cheque_book_id')->index();
                $table->unsignedBigInteger('leaf_number');
                $table->string('status', 16)->default('unused')->index();
                $table->unsignedBigInteger('cheque_id')->nullable()->index();
                $table->timestamps();

                $table->unique(['cheque_book_id', 'leaf_number']);
            });
        }

        if (Schema::hasTable('cheques') && ! Schema::hasColumn('cheques', 'cheque_book_leaf_id')) {
            Schema::table('cheques', function (Blueprint $table) {
                $table->unsignedBigInteger('cheque_book_leaf_id')->nullable()->after('cheque_number')->index();
            });
        }

        if (Schema::hasTable('cheque_books') && Schema::hasTable('ateliers')) {
            try {
                Schema::table('cheque_books', function (Blueprint $table) {
                    $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
                });
            } catch (\Throwable $e) {
            }
        }

        if (Schema::hasTable('cheque_books') && Schema::hasTable('shop_accounts')) {
            try {
                Schema::table('cheque_books', function (Blueprint $table) {
                    $table->foreign('shop_account_id')->references('id')->on('shop_accounts')->nullOnDelete();
                });
            } catch (\Throwable $e) {
            }
        }

        if (Schema::hasTable('cheque_book_leaves') && Schema::hasTable('cheque_books')) {
            try {
                Schema::table('cheque_book_leaves', function (Blueprint $table) {
                    $table->foreign('cheque_book_id')->references('id')->on('cheque_books')->cascadeOnDelete();
                });
            } catch (\Throwable $e) {
            }
        }

        if (Schema::hasTable('cheque_book_leaves') && Schema::hasTable('cheques')) {
            try {
                Schema::table('cheque_book_leaves', function (Blueprint $table) {
                    $table->foreign('cheque_id')->references('id')->on('cheques')->nullOnDelete();
                });
            } catch (\Throwable $e) {
            }
        }

        if (Schema::hasTable('cheques') && Schema::hasTable('cheque_book_leaves') && Schema::hasColumn('cheques', 'cheque_book_leaf_id')) {
            try {
                Schema::table('cheques', function (Blueprint $table) {
                    $table->foreign('cheque_book_leaf_id')->references('id')->on('cheque_book_leaves')->nullOnDelete();
                });
            } catch (\Throwable $e) {
            }
        }
    }

    public function down()
    {
        if (Schema::hasTable('cheques') && Schema::hasColumn('cheques', 'cheque_book_leaf_id')) {
            Schema::table('cheques', function (Blueprint $table) {
                try {
                    $table->dropForeign(['cheque_book_leaf_id']);
                } catch (\Throwable $e) {
                }
                $table->dropColumn('cheque_book_leaf_id');
            });
        }

        Schema::dropIfExists('cheque_book_leaves');
        Schema::dropIfExists('cheque_books');
    }
}
