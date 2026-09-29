<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class MoadianShopSetting extends Model
{
    public const MODE_SELF_TSP = 'self_tsp';

    public const MODE_TSP = 'tsp';

    public const ENV_SANDBOX = 'sandbox';

    public const ENV_PRODUCTION = 'production';

    protected $fillable = [
        'atelier_id',
        'enabled',
        'environment',
        'connection_mode',
        'memory_id',
        'private_key',
        'certificate',
        'tsp_provider',
        'tsp_credentials',
        'price_includes_vat',
        'default_invoice_type',
        'auto_type1_with_buyer',
        'default_vat_rate',
        'default_sstid',
        'default_sstt',
        'unit_code_piece',
        'unit_code_kg',
        'unit_code_meter',
        'late_threshold_days',
        'start_purchase_id',
        'started_at',
        'paused_reason',
        'last_verified_at',
        'last_run_at',
    ];

    protected $hidden = ['private_key', 'tsp_credentials'];

    protected $casts = [
        'enabled' => 'boolean',
        'price_includes_vat' => 'boolean',
        'auto_type1_with_buyer' => 'boolean',
        'default_invoice_type' => 'integer',
        'default_vat_rate' => 'float',
        'late_threshold_days' => 'integer',
        'start_purchase_id' => 'integer',
        'private_key' => 'encrypted',
        'tsp_credentials' => 'encrypted',
        'started_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public static function tableReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = Schema::hasTable('moadian_shop_settings')
                    && Schema::hasTable('moadian_documents')
                    && Schema::hasTable('moadian_sale_links');
            } catch (\Throwable $e) {
                $ready = false;
            }
        }

        return $ready;
    }

    public static function forAtelier(int $atelierId): self
    {
        return static::query()->firstOrNew(['atelier_id' => $atelierId]);
    }

    public function hasPrivateKey(): bool
    {
        try {
            return trim((string) $this->private_key) !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return string[] دلایل آماده نبودن ارسال (خالی = آماده)
     */
    public function readinessProblems(): array
    {
        $problems = [];
        if (trim((string) $this->memory_id) === '') {
            $problems[] = 'شناسه یکتای حافظه مالیاتی وارد نشده است.';
        }
        if ($this->connection_mode === self::MODE_SELF_TSP) {
            if (! $this->hasPrivateKey()) {
                $problems[] = 'کلید خصوصی ثبت نشده است.';
            }
            if (trim((string) $this->certificate) === '') {
                $problems[] = 'گواهی امضا (certificate) ثبت نشده است.';
            }
        } else {
            $problems[] = 'اتصال از طریق شرکت معتمد هنوز پیاده‌سازی نشده است؛ روش اتصال مستقیم را انتخاب کنید.';
        }

        try {
            $seller = FormalInvoiceSellerProfile::where('atelier_id', $this->atelier_id)->first();
        } catch (\Throwable $e) {
            $seller = null;
        }
        if (! $seller || (trim((string) $seller->economic_code) === '' && trim((string) $seller->national_id) === '')) {
            $problems[] = 'کد اقتصادی یا شناسه ملی فروشنده در «مشخصات فاکتور رسمی» ثبت نشده است.';
        }

        return $problems;
    }

    public function canSend(): bool
    {
        return $this->enabled && trim((string) $this->paused_reason) === '' && $this->readinessProblems() === [];
    }
}
