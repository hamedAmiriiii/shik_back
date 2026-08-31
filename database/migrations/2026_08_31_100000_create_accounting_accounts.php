<?php

use App\Models\ShopAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAccountingAccounts extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('accounting_accounts')) {
            Schema::create('accounting_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('code', 32);
                $table->string('name');
                $table->string('level', 16);
                $table->string('nature', 16);
                $table->string('kind', 16);
                $table->boolean('is_system')->default(false);
                $table->string('linked_type', 32)->nullable();
                $table->unsignedBigInteger('linked_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
                $table->foreign('parent_id', 'accounting_accounts_parent_fk')
                    ->references('id')
                    ->on('accounting_accounts')
                    ->nullOnDelete();
                $table->unique(['atelier_id', 'code'], 'accounting_accounts_atelier_code_unique');
                $table->index(
                    ['atelier_id', 'linked_type', 'linked_id'],
                    'accounting_accounts_link_index'
                );
                $table->index(['atelier_id', 'level']);
                $table->index(['atelier_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('shop_accounts') || ! Schema::hasTable('ateliers')) {
            return;
        }

        $atelierIds = \Illuminate\Support\Facades\DB::table('ateliers')->pluck('id');
        foreach ($atelierIds as $atelierId) {
            ShopAccount::ensureDefaultsForAtelier((int) $atelierId);
        }
    }

    public function down()
    {
        Schema::dropIfExists('accounting_accounts');
    }
}
