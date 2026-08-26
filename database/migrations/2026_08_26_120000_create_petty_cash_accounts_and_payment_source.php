<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreatePettyCashAccountsAndPaymentSource extends Migration
{
    public function up()
    {
        if (Schema::hasTable('shop_accounts') && ! Schema::hasColumn('shop_accounts', 'type')) {
            Schema::table('shop_accounts', function (Blueprint $table) {
                $table->string('type', 32)->default('shop')->after('name')
                    ->comment('shop=حساب اصلی فروشگاه | petty_cash=تنخواه');
                $table->index(['atelier_id', 'type']);
            });

            DB::table('shop_accounts')->update(['type' => 'shop']);
        }

        if (! Schema::hasTable('shop_account_transfers')) {
            Schema::create('shop_account_transfers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedBigInteger('from_shop_account_id');
                $table->unsignedBigInteger('to_shop_account_id');
                $table->decimal('amount', 15, 2);
                $table->date('date');
                $table->string('title')->nullable();
                $table->text('description')->nullable();
                $table->string('user_name')->nullable();
                $table->timestamps();

                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
                $table->foreign('from_shop_account_id', 'sat_from_account_fk')
                    ->references('id')->on('shop_accounts')->cascadeOnDelete();
                $table->foreign('to_shop_account_id', 'sat_to_account_fk')
                    ->references('id')->on('shop_accounts')->cascadeOnDelete();
                $table->index(['atelier_id', 'date']);
            });
        }

        foreach (['expenses', 'invoices'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'shop_account_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->unsignedBigInteger('shop_account_id')->nullable()->after('atelier_id')
                        ->comment('حسابی که مبلغ از آن برداشت شده است');
                    $table->foreign('shop_account_id', substr($tableName, 0, 3).'_shop_account_fk')
                        ->references('id')->on('shop_accounts')->nullOnDelete();
                });
            }
        }
    }

    public function down()
    {
        foreach (['expenses', 'invoices'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'shop_account_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->dropForeign(substr($tableName, 0, 3).'_shop_account_fk');
                    $table->dropColumn('shop_account_id');
                });
            }
        }

        Schema::dropIfExists('shop_account_transfers');

        if (Schema::hasTable('shop_accounts') && Schema::hasColumn('shop_accounts', 'type')) {
            Schema::table('shop_accounts', function (Blueprint $table) {
                $table->dropIndex(['atelier_id', 'type']);
                $table->dropColumn('type');
            });
        }
    }
}
