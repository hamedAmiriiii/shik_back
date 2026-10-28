<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PurchaseVatService
{
    public const MODE_NONE = 'none';

    public const MODE_PERCENT = 'percent';

    public const MODE_AMOUNT = 'amount';

    public const SETTING_ENABLED = 'purchase_vat_enabled';

    public static function enabledForAtelier(int $atelierId): bool
    {
        if ($atelierId <= 0) {
            return false;
        }

        $raw = Setting::query()
            ->where('atelier_id', $atelierId)
            ->where('key', self::SETTING_ENABLED)
            ->value('value');

        return $raw === '1' || $raw === 1 || $raw === true || $raw === 'true';
    }

    public static function columnsReady(string $table): bool
    {
        return Schema::hasTable($table)
            && Schema::hasColumn($table, 'vat_mode')
            && Schema::hasColumn($table, 'vat_amount');
    }

    /**
     * @return array{vat_mode:string,vat_percent:?float,vat_amount:float}
     */
    public static function normalize(int $atelierId, float $baseAmount, array $fields, string $table): array
    {
        $baseAmount = round(max(0, $baseAmount), 2);
        $empty = [
            'vat_mode' => self::MODE_NONE,
            'vat_percent' => null,
            'vat_amount' => 0.0,
        ];

        if (! self::columnsReady($table) || ! self::enabledForAtelier($atelierId)) {
            return $empty;
        }

        $mode = strtolower(trim((string) ($fields['vat_mode'] ?? self::MODE_NONE)));
        if (! in_array($mode, [self::MODE_NONE, self::MODE_PERCENT, self::MODE_AMOUNT], true)) {
            throw new RuntimeException('نوع ارزش افزوده نامعتبر است.');
        }

        if ($mode === self::MODE_NONE) {
            return $empty;
        }

        if ($mode === self::MODE_PERCENT) {
            $percent = round((float) ($fields['vat_percent'] ?? 0), 2);
            if ($percent < 0 || $percent > 100) {
                throw new RuntimeException('درصد ارزش افزوده باید بین ۰ تا ۱۰۰ باشد.');
            }
            $vat = round($baseAmount * $percent / 100, 2);

            return [
                'vat_mode' => self::MODE_PERCENT,
                'vat_percent' => $percent,
                'vat_amount' => $vat,
            ];
        }

        $vat = round((float) ($fields['vat_amount'] ?? 0), 2);
        if ($vat < 0) {
            throw new RuntimeException('مبلغ ارزش افزوده نمی‌تواند منفی باشد.');
        }

        return [
            'vat_mode' => self::MODE_AMOUNT,
            'vat_percent' => null,
            'vat_amount' => $vat,
        ];
    }

    /**
     * وقتی مبلغ پایه (مثلاً از آیتم‌ها) عوض می‌شود، VAT درصدی را دوباره حساب کن.
     */
    public static function refreshForBaseChange(Model $model): void
    {
        if (! ($model instanceof Invoice || $model instanceof Expense)) {
            return;
        }
        $table = $model->getTable();
        if (! self::columnsReady($table)) {
            return;
        }
        if (($model->vat_mode ?? self::MODE_NONE) !== self::MODE_PERCENT) {
            return;
        }
        $percent = (float) ($model->vat_percent ?? 0);
        $vat = round((float) $model->amount * $percent / 100, 2);
        if (round((float) ($model->vat_amount ?? 0), 2) !== $vat) {
            $model->update(['vat_amount' => $vat]);
        }
    }

    public static function vatAmount(Model $model): float
    {
        if (! ($model instanceof Invoice || $model instanceof Expense)) {
            return 0.0;
        }
        if (! self::columnsReady($model->getTable())) {
            return 0.0;
        }

        return round((float) ($model->vat_amount ?? 0), 2);
    }

    public static function payableAmount(Model $model): float
    {
        if (! ($model instanceof Invoice || $model instanceof Expense)) {
            return round((float) ($model->amount ?? 0), 2);
        }

        return round((float) $model->amount + self::vatAmount($model), 2);
    }

    /**
     * @return array<string, mixed>
     */
    public static function requestRules(): array
    {
        return [
            'vat_mode' => 'nullable|string|in:none,percent,amount',
            'vat_percent' => 'nullable|numeric|min:0|max:100',
            'vat_amount' => 'nullable|numeric|min:0',
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function applyToCreateFields(int $atelierId, array &$fields, string $table): void
    {
        $base = round((float) ($fields['amount'] ?? 0), 2);
        $vat = self::normalize($atelierId, $base, $fields, $table);
        $fields['vat_mode'] = $vat['vat_mode'];
        $fields['vat_percent'] = $vat['vat_percent'];
        $fields['vat_amount'] = $vat['vat_amount'];
    }
}
