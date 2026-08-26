<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateShopAccountsAndReconciliationAccountDeposits extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('shop_accounts')) {
            Schema::create('shop_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->string('name');
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('legacy_slot', 32)->nullable()->comment('account_1 | account_2');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
                $table->index(['atelier_id', 'is_active']);
                $table->unique(['atelier_id', 'legacy_slot']);
            });
        }

        if (! Schema::hasTable('daily_shop_reconciliation_account_deposits')) {
            Schema::create('daily_shop_reconciliation_account_deposits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('reconciliation_id');
                $table->unsignedBigInteger('shop_account_id');
                $table->decimal('amount', 15, 2)->default(0);
                $table->unsignedBigInteger('deposit_record_id')->nullable();
                $table->timestamps();

                $table->foreign('reconciliation_id', 'dsrad_recon_fk')
                    ->references('id')
                    ->on('daily_shop_reconciliations')
                    ->cascadeOnDelete();
                $table->foreign('shop_account_id', 'dsrad_account_fk')
                    ->references('id')
                    ->on('shop_accounts')
                    ->cascadeOnDelete();
                $table->foreign('deposit_record_id', 'dsrad_deposit_fk')
                    ->references('id')
                    ->on('daily_shop_reconciliation_deposits')
                    ->nullOnDelete();
                $table->unique(['reconciliation_id', 'shop_account_id'], 'dsrad_recon_account_unique');
            });
        }

        if (Schema::hasTable('daily_shop_reconciliation_deposits')
            && ! Schema::hasColumn('daily_shop_reconciliation_deposits', 'shop_account_id')
        ) {
            Schema::table('daily_shop_reconciliation_deposits', function (Blueprint $table) {
                $table->unsignedBigInteger('shop_account_id')->nullable()->after('atelier_id');
                $table->foreign('shop_account_id', 'dsrd_shop_account_fk')
                    ->references('id')
                    ->on('shop_accounts')
                    ->nullOnDelete();
            });
        }

        $this->seedDefaultAccountsAndBackfill();
    }

    protected function seedDefaultAccountsAndBackfill(): void
    {
        if (! Schema::hasTable('shop_accounts') || ! Schema::hasTable('ateliers')) {
            return;
        }

        $atelierIds = DB::table('ateliers')->pluck('id');
        $now = now();

        foreach ($atelierIds as $atelierId) {
            $existing = DB::table('shop_accounts')
                ->where('atelier_id', $atelierId)
                ->whereIn('legacy_slot', ['account_1', 'account_2'])
                ->pluck('id', 'legacy_slot');

            if (! isset($existing['account_1'])) {
                DB::table('shop_accounts')->insert([
                    'atelier_id' => $atelierId,
                    'name' => 'حساب ۱',
                    'sort_order' => 1,
                    'legacy_slot' => 'account_1',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (! isset($existing['account_2'])) {
                DB::table('shop_accounts')->insert([
                    'atelier_id' => $atelierId,
                    'name' => 'حساب ۲',
                    'sort_order' => 2,
                    'legacy_slot' => 'account_2',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (! Schema::hasTable('daily_shop_reconciliations')
            || ! Schema::hasTable('daily_shop_reconciliation_account_deposits')
        ) {
            return;
        }

        $reconciliations = DB::table('daily_shop_reconciliations')->get([
            'id',
            'atelier_id',
            'deposit_account_1',
            'deposit_account_2',
            'deposit_record_account_1_id',
            'deposit_record_account_2_id',
        ]);

        foreach ($reconciliations as $recon) {
            $accounts = DB::table('shop_accounts')
                ->where('atelier_id', $recon->atelier_id)
                ->whereIn('legacy_slot', ['account_1', 'account_2'])
                ->pluck('id', 'legacy_slot');

            foreach ([
                'account_1' => [
                    'amount' => (float) $recon->deposit_account_1,
                    'deposit_record_id' => $recon->deposit_record_account_1_id,
                ],
                'account_2' => [
                    'amount' => (float) $recon->deposit_account_2,
                    'deposit_record_id' => $recon->deposit_record_account_2_id,
                ],
            ] as $slot => $data) {
                if (! isset($accounts[$slot])) {
                    continue;
                }

                $shopAccountId = (int) $accounts[$slot];
                $exists = DB::table('daily_shop_reconciliation_account_deposits')
                    ->where('reconciliation_id', $recon->id)
                    ->where('shop_account_id', $shopAccountId)
                    ->exists();

                if ($exists) {
                    // اگر ردیف با مبلغ ۰ است و ستون قدیمی مقدار دارد، اصلاح کن
                    $legacyAmount = (float) $data['amount'];
                    if ($legacyAmount > 0) {
                        DB::table('daily_shop_reconciliation_account_deposits')
                            ->where('reconciliation_id', $recon->id)
                            ->where('shop_account_id', $shopAccountId)
                            ->where('amount', '<=', 0)
                            ->update([
                                'amount' => $legacyAmount,
                                'updated_at' => $now,
                            ]);
                    }
                    continue;
                }

                // فقط ردیف‌هایی که مبلغ یا رکورد واریز دارند منتقل می‌شوند
                if ($data['amount'] <= 0 && empty($data['deposit_record_id'])) {
                    continue;
                }

                $depositRecordId = $data['deposit_record_id'] ? (int) $data['deposit_record_id'] : null;
                if ($depositRecordId && Schema::hasTable('daily_shop_reconciliation_deposits')) {
                    $depositExists = DB::table('daily_shop_reconciliation_deposits')
                        ->where('id', $depositRecordId)
                        ->exists();
                    if (! $depositExists) {
                        $depositRecordId = null;
                    }
                }

                DB::table('daily_shop_reconciliation_account_deposits')->insert([
                    'reconciliation_id' => $recon->id,
                    'shop_account_id' => $shopAccountId,
                    'amount' => $data['amount'],
                    'deposit_record_id' => $depositRecordId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($depositRecordId && Schema::hasColumn('daily_shop_reconciliation_deposits', 'shop_account_id')) {
                    DB::table('daily_shop_reconciliation_deposits')
                        ->where('id', $depositRecordId)
                        ->whereNull('shop_account_id')
                        ->update(['shop_account_id' => $shopAccountId]);
                }
            }
        }
    }

    public function down()
    {
        if (Schema::hasTable('daily_shop_reconciliation_deposits')
            && Schema::hasColumn('daily_shop_reconciliation_deposits', 'shop_account_id')
        ) {
            Schema::table('daily_shop_reconciliation_deposits', function (Blueprint $table) {
                $table->dropForeign('dsrd_shop_account_fk');
                $table->dropColumn('shop_account_id');
            });
        }

        Schema::dropIfExists('daily_shop_reconciliation_account_deposits');
        Schema::dropIfExists('shop_accounts');
    }
}
