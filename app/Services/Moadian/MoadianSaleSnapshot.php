<?php

namespace App\Services\Moadian;

use App\Models\FormalInvoiceBuyerProfile;
use App\Models\MoadianShopSetting;
use App\Models\MoadianStuffId;
use App\Models\Purchase;
use App\Models\PurchasedProduct;
use Carbon\Carbon;

/**
 * تصویر فقط‌خواندنی یک فروش برای سامانه مؤدیان. هیچ چیزی روی فروش نوشته نمی‌شود.
 *
 * اعتبار باشگاه (credit_used) مثل تخفیف فاکتور از مبلغ مشمول مالیات کم می‌شود.
 */
class MoadianSaleSnapshot
{
    public const SETM_CASH = 1;

    public const SETM_CREDIT = 2;

    public const SETM_MIXED = 3;

    /** @var array<string, MoadianStuffId> */
    private array $stuffCache = [];

    public function __construct(private MoadianShopSetting $settings)
    {
        $this->stuffCache = MoadianStuffId::query()
            ->where('atelier_id', $settings->atelier_id)
            ->get()
            ->keyBy('sstid')
            ->all();
    }

    /**
     * @return array{
     *   purchase_id:int, issued_at:int, invoice_type:int, buyer:?array, lines:array<string,array>,
     *   discount_toman:float, setm:int, immediate_paid_toman:float, fingerprint:string, warnings:string[]
     * }
     */
    public function build(Purchase $purchase): array
    {
        $purchase->loadMissing(['purchasedProducts.product', 'purchasedProducts.producedGood', 'purchasedProducts.rawMaterial']);

        $warnings = [];
        $lines = [];
        foreach ($purchase->purchasedProducts as $pp) {
            $qty = round((float) $pp->quantity, 3);
            $price = round((float) $pp->sale_price, 2);
            if ($qty <= 0 || $price <= 0) {
                continue;
            }
            $key = $this->lineKey($pp, $price);

            if (! isset($lines[$key])) {
                $sstid = $this->resolveSstid($pp);
                $stuff = $sstid !== '' ? ($this->stuffCache[$sstid] ?? null) : null;
                $unitType = $pp->product->unit_type ?? 'piece';
                $lines[$key] = [
                    'key' => $key,
                    'purchased_product_id' => (int) $pp->id,
                    'title' => mb_substr(trim((string) ($pp->display_name ?? '')) ?: 'کالا', 0, 400),
                    'qty' => 0.0,
                    'unit_price_toman' => $price,
                    'unit_type' => $unitType,
                    'mu' => $this->unitCode($unitType, $stuff),
                    'sstid' => $sstid,
                    'vra' => $stuff ? (float) $stuff->vat_rate : (float) $this->settings->default_vat_rate,
                    'odr' => $stuff ? (float) $stuff->other_tax_rate : 0.0,
                    'odt' => $stuff ? (string) ($stuff->other_tax_subject ?? '') : '',
                ];
                if ($sstid === '') {
                    $warnings[] = 'شناسه کالا/خدمت برای «'.$lines[$key]['title'].'» تعیین نشده است.';
                }
            }
            $lines[$key]['qty'] = round($lines[$key]['qty'] + $qty, 3);
        }
        ksort($lines);

        $lineTotal = 0.0;
        foreach ($lines as $line) {
            $lineTotal += $line['qty'] * $line['unit_price_toman'];
        }

        $discount = $purchase->isInstallment() ? 0.0 : max(0.0, (float) $purchase->discount_amount);
        $discount += max(0.0, (float) $purchase->credit_used);
        $discount = min($discount, $lineTotal);

        $buyer = $this->buyer($purchase);
        $invoiceType = 2;
        if ($buyer !== null && ($this->settings->auto_type1_with_buyer || (int) $this->settings->default_invoice_type === 1)) {
            $invoiceType = 1;
        } elseif ((int) $this->settings->default_invoice_type === 1) {
            $warnings[] = 'مشخصات خریدار (کد اقتصادی/شناسه ملی) ثبت نشده؛ صورتحساب نوع دوم صادر شد.';
        }

        $snapshot = [
            'purchase_id' => (int) $purchase->id,
            'issued_at' => $this->issuedAt($purchase),
            'invoice_type' => $invoiceType,
            'buyer' => $invoiceType === 1 ? $buyer : null,
            'lines' => $lines,
            'discount_toman' => round($discount, 2),
            'setm' => $this->settlementMethod($purchase),
            'immediate_paid_toman' => round($purchase->immediatePaidAmount(), 2),
            'warnings' => array_values(array_unique($warnings)),
        ];
        $snapshot['fingerprint'] = self::fingerprint($snapshot);

        return $snapshot;
    }

    public static function fingerprint(array $snapshot): string
    {
        $lines = [];
        foreach ($snapshot['lines'] as $line) {
            $lines[] = [$line['key'], number_format($line['qty'], 3, '.', ''), $line['sstid'], $line['vra'], $line['odr']];
        }

        return hash('sha256', MoadianCrypto::json([
            'type' => $snapshot['invoice_type'],
            'buyer' => $snapshot['buyer']['tinb'] ?? null,
            'lines' => $lines,
            'discount' => number_format($snapshot['discount_toman'], 2, '.', ''),
        ]));
    }

    public static function isEmpty(array $snapshot): bool
    {
        return $snapshot['lines'] === [];
    }

    private function lineKey(PurchasedProduct $pp, float $price): string
    {
        if ($pp->produced_good_id) {
            $id = 'g'.$pp->produced_good_id;
        } elseif ($pp->raw_material_id) {
            $id = 'r'.$pp->raw_material_id;
        } elseif ($pp->product_id) {
            $id = 'p'.$pp->product_id;
        } else {
            $id = 'n'.substr(md5((string) $pp->item_name), 0, 12);
        }

        return $id.'@'.number_format($price, 2, '.', '');
    }

    private function resolveSstid(PurchasedProduct $pp): string
    {
        $sstid = '';
        if ($pp->product_id && ! $pp->produced_good_id && ! $pp->raw_material_id && $pp->product) {
            $sstid = trim((string) ($pp->product->moadian_sstid ?? ''));
        }
        if ($sstid === '') {
            $sstid = trim((string) ($this->settings->default_sstid ?? ''));
        }

        return $sstid;
    }

    private function unitCode(string $unitType, ?MoadianStuffId $stuff): string
    {
        if ($stuff && trim((string) $stuff->unit_code) !== '') {
            return trim((string) $stuff->unit_code);
        }
        $code = match ($unitType) {
            'kg' => $this->settings->unit_code_kg,
            'meter' => $this->settings->unit_code_meter,
            default => $this->settings->unit_code_piece,
        };

        return trim((string) $code);
    }

    private function issuedAt(Purchase $purchase): int
    {
        $raw = $purchase->getRawOriginal('created_at');
        if (! $raw) {
            return time();
        }

        return Carbon::parse($raw, config('app.timezone') ?: 'Asia/Tehran')->getTimestamp();
    }

    private function settlementMethod(Purchase $purchase): int
    {
        if ($purchase->isDebt() || $purchase->isInstallment()) {
            return self::SETM_CREDIT;
        }
        if ($purchase->isCheque()) {
            return $purchase->immediatePaidAmount() > 0 ? self::SETM_MIXED : self::SETM_CREDIT;
        }

        return self::SETM_CASH;
    }

    private function buyer(Purchase $purchase): ?array
    {
        $phone = preg_replace('/\D+/', '', (string) ($purchase->phone ?? '')) ?? '';
        if (strlen($phone) === 10 && str_starts_with($phone, '9')) {
            $phone = '0'.$phone;
        }
        if ($phone === '') {
            return null;
        }

        try {
            $profile = FormalInvoiceBuyerProfile::query()
                ->where('atelier_id', $this->settings->atelier_id)
                ->where('phone', $phone)
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
        if (! $profile) {
            return null;
        }

        $nationalId = preg_replace('/\D+/', '', (string) $profile->national_id) ?? '';
        $economicCode = preg_replace('/\D+/', '', (string) $profile->economic_code) ?? '';
        $tinb = $economicCode !== '' ? $economicCode : $nationalId;
        if ($tinb === '') {
            return null;
        }

        $tob = strlen($nationalId) === 11 || strlen($tinb) === 11 ? 2 : 1;
        $postal = preg_replace('/\D+/', '', (string) $profile->postal_code) ?? '';

        return array_filter([
            'tob' => $tob,
            'tinb' => $tinb,
            'bid' => $nationalId !== '' ? $nationalId : null,
            'bpc' => strlen($postal) === 10 ? $postal : null,
            'name' => $profile->full_name,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
