<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopAccount extends Model
{
    public const LEGACY_ACCOUNT_1 = 'account_1';

    public const LEGACY_ACCOUNT_2 = 'account_2';

    public const DEFAULTS = [
        ['name' => 'حساب ۱', 'sort_order' => 1, 'legacy_slot' => self::LEGACY_ACCOUNT_1],
        ['name' => 'حساب ۲', 'sort_order' => 2, 'legacy_slot' => self::LEGACY_ACCOUNT_2],
    ];

    /** @var array<int, bool> */
    protected static array $backfillDoneForAtelier = [];

    protected $fillable = [
        'atelier_id',
        'name',
        'sort_order',
        'legacy_slot',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function reconciliationDeposits(): HasMany
    {
        return $this->hasMany(DailyShopReconciliationAccountDeposit::class, 'shop_account_id');
    }

    /**
     * ایجاد حساب‌های پیش‌فرض (حساب ۱ و ۲) و انتقال واریزهای قدیمی.
     */
    public static function ensureDefaultsForAtelier(int $atelierId): void
    {
        if ($atelierId <= 0 || ! Schema::hasTable('shop_accounts')) {
            return;
        }

        foreach (self::DEFAULTS as $default) {
            static::query()->firstOrCreate(
                [
                    'atelier_id' => $atelierId,
                    'legacy_slot' => $default['legacy_slot'],
                ],
                [
                    'name' => $default['name'],
                    'sort_order' => $default['sort_order'],
                    'is_active' => true,
                ]
            );
        }

        self::backfillLegacyDepositsForAtelier($atelierId);
    }

    /**
     * انتقال deposit_account_1/2 قدیمی به ردیف‌های حساب جدید (قابل تکرار، امن برای FK).
     */
    public static function backfillLegacyDepositsForAtelier(int $atelierId): void
    {
        if (! Schema::hasTable('daily_shop_reconciliations')
            || ! Schema::hasTable('daily_shop_reconciliation_account_deposits')
        ) {
            return;
        }

        if (isset(self::$backfillDoneForAtelier[$atelierId])) {
            return;
        }
        self::$backfillDoneForAtelier[$atelierId] = true;

        $accounts = static::query()
            ->forAtelier($atelierId)
            ->whereIn('legacy_slot', [self::LEGACY_ACCOUNT_1, self::LEGACY_ACCOUNT_2])
            ->get()
            ->keyBy('legacy_slot');

        if ($accounts->isEmpty()) {
            return;
        }

        $hasDepositTable = Schema::hasTable('daily_shop_reconciliation_deposits');
        $hasShopAccountCol = $hasDepositTable
            && Schema::hasColumn('daily_shop_reconciliation_deposits', 'shop_account_id');

        $recons = DB::table('daily_shop_reconciliations')
            ->where('atelier_id', $atelierId)
            ->get([
                'id',
                'deposit_account_1',
                'deposit_account_2',
                'deposit_record_account_1_id',
                'deposit_record_account_2_id',
            ]);

        $now = now();

        foreach ($recons as $recon) {
            foreach ([
                self::LEGACY_ACCOUNT_1 => [
                    'amount' => (float) $recon->deposit_account_1,
                    'deposit_record_id' => $recon->deposit_record_account_1_id,
                ],
                self::LEGACY_ACCOUNT_2 => [
                    'amount' => (float) $recon->deposit_account_2,
                    'deposit_record_id' => $recon->deposit_record_account_2_id,
                ],
            ] as $slot => $data) {
                /** @var self|null $account */
                $account = $accounts->get($slot);
                if (! $account) {
                    continue;
                }

                if ($data['amount'] <= 0 && empty($data['deposit_record_id'])) {
                    continue;
                }

                $depositRecordId = $data['deposit_record_id'] ? (int) $data['deposit_record_id'] : null;
                if ($depositRecordId && $hasDepositTable) {
                    $exists = DB::table('daily_shop_reconciliation_deposits')
                        ->where('id', $depositRecordId)
                        ->exists();
                    if (! $exists) {
                        $depositRecordId = null;
                    }
                }

                $existing = DB::table('daily_shop_reconciliation_account_deposits')
                    ->where('reconciliation_id', $recon->id)
                    ->where('shop_account_id', $account->id)
                    ->first();

                if ($existing) {
                    // اگر ردیف با مبلغ ۰ ساخته شده ولی ستون قدیمی مقدار دارد، اصلاح کن
                    if ((float) $existing->amount <= 0 && $data['amount'] > 0) {
                        DB::table('daily_shop_reconciliation_account_deposits')
                            ->where('id', $existing->id)
                            ->update([
                                'amount' => $data['amount'],
                                'deposit_record_id' => $depositRecordId ?? $existing->deposit_record_id,
                                'updated_at' => $now,
                            ]);
                    }
                } else {
                    DB::table('daily_shop_reconciliation_account_deposits')->insert([
                        'reconciliation_id' => $recon->id,
                        'shop_account_id' => $account->id,
                        'amount' => $data['amount'],
                        'deposit_record_id' => $depositRecordId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if ($depositRecordId && $hasShopAccountCol) {
                    DB::table('daily_shop_reconciliation_deposits')
                        ->where('id', $depositRecordId)
                        ->whereNull('shop_account_id')
                        ->update(['shop_account_id' => $account->id]);
                }
            }
        }
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForAtelier($query, int $atelierId)
    {
        return $query->where('atelier_id', $atelierId);
    }
}
