<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class TableOrder extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    public const METHOD_ONLINE = 'online';
    public const METHOD_CARD_TO_CARD = 'card_to_card';
    public const METHOD_POS = 'pos';

    public const CANCELLED_BY_CUSTOMER = 'customer';
    public const CANCELLED_BY_STAFF = 'staff';
    public const CANCELLED_BY_SYSTEM = 'system';

    /** سفارش آنلاینِ پرداخت‌نشده بعد از این مدت خودکار لغو می‌شود تا میز آزاد شود. */
    public const ONLINE_PAYMENT_TTL_MINUTES = 15;

    public const SETTING_SHOP_MERCHANT_ID = 'shop_zarinpal_merchant_id';

    protected $fillable = [
        'atelier_id',
        'shop_table_id',
        'table_label',
        'phone',
        'note',
        'total_amount',
        'use_credit',
        'payment_method',
        'receipt_path',
        'status',
        'purchase_id',
        'gateway_payment_id',
        'online_paid_at',
        'online_ref_id',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'use_credit' => 'boolean',
        'online_paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    private static ?bool $hasOnlineColumns = null;

    public function shopTable(): BelongsTo
    {
        return $this->belongsTo(ShopTable::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TableOrderItem::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** ستون‌های پرداخت آنلاین / لغو نرم (SQL دستی ممکن است هنوز اجرا نشده باشد). */
    public static function hasOnlineColumns(): bool
    {
        if (self::$hasOnlineColumns === null) {
            try {
                self::$hasOnlineColumns = Schema::hasColumn('table_orders', 'online_paid_at');
            } catch (\Throwable $e) {
                self::$hasOnlineColumns = false;
            }
        }

        return self::$hasOnlineColumns;
    }

    public function isPaidOnline(): bool
    {
        return self::hasOnlineColumns() && $this->online_paid_at !== null;
    }

    /**
     * فقط سفارش‌هایی که پرداخت درگاه برایشان شروع شده؛ سفارش‌های «آنلاین» قدیمی (قبل از فعال شدن درگاه)
     * یا سفارشی که شروع پرداختش خطا داد، مثل سفارش عادی به پرسنل نمایش داده می‌شوند.
     */
    public function isAwaitingOnlinePayment(): bool
    {
        if (! self::hasOnlineColumns()) {
            return false;
        }

        return $this->isPending()
            && $this->payment_method === self::METHOD_ONLINE
            && $this->gateway_payment_id !== null
            && $this->online_paid_at === null;
    }

    public function isOnlinePaymentExpired(): bool
    {
        $since = $this->updated_at ?? $this->created_at;
        if (! $this->isAwaitingOnlinePayment() || ! $since) {
            return false;
        }

        return $since->lt(now()->subMinutes(self::ONLINE_PAYMENT_TTL_MINUTES));
    }

    /**
     * لغو نرم؛ اگر ستون‌ها نباشند رفتار قدیمی (حذف) حفظ می‌شود.
     */
    public function markCancelled(string $by): void
    {
        if (! self::hasOnlineColumns()) {
            $this->delete();

            return;
        }

        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_by = $by;
        $this->cancelled_at = now();
        $this->save();
    }

    /**
     * سفارش‌هایی که پرسنل باید ببیند: سفارش آنلاینِ پرداخت‌نشده تا پرداخت نشود نمایش داده نمی‌شود.
     */
    public function scopeVisibleToStaff($query)
    {
        if (! self::hasOnlineColumns()) {
            return $query;
        }

        return $query->where(function ($q) {
            $q->where('status', '!=', self::STATUS_PENDING)
                ->orWhere('payment_method', '!=', self::METHOD_ONLINE)
                ->orWhereNull('payment_method')
                ->orWhereNull('gateway_payment_id')
                ->orWhereNotNull('online_paid_at');
        });
    }

    public static function paymentMethodKeys(): array
    {
        return [
            self::METHOD_ONLINE,
            self::METHOD_CARD_TO_CARD,
            self::METHOD_POS,
        ];
    }

    public static function paymentMethodLabel(?string $method): ?string
    {
        $labels = [
            self::METHOD_ONLINE => 'آنلاین',
            self::METHOD_CARD_TO_CARD => 'کارت به کارت',
            self::METHOD_POS => 'کارتخوان فروشگاه',
        ];

        return $labels[$method] ?? $method;
    }

    public static function paymentMethodSettingKey(string $method): string
    {
        return 'table_payment_' . $method . '_enabled';
    }

    /** پیش‌فرض: کارت‌به‌کارت و کارتخوان روشن، آنلاین خاموش تا فروشگاه فعالش کند. */
    public static function paymentMethodDefaultEnabled(string $method): bool
    {
        return $method !== self::METHOD_ONLINE;
    }

    /** مرچنت اختصاصی زرین‌پال فروشگاه (در زمینهٔ Setting فعلی). */
    public static function shopZarinpalMerchantId(): ?string
    {
        $value = trim((string) Setting::get(self::SETTING_SHOP_MERCHANT_ID, ''));

        return preg_match('/^[A-Za-z0-9-]{20,64}$/', $value) ? $value : null;
    }

    public static function onlineGatewayAvailable(): bool
    {
        return self::shopZarinpalMerchantId() !== null
            || trim((string) config('zarinpal.merchant_id')) !== '';
    }

    public static function isPaymentMethodEnabled(string $method): bool
    {
        if (! in_array($method, self::paymentMethodKeys(), true)) {
            return false;
        }

        $enabled = Setting::isEnabled(
            self::paymentMethodSettingKey($method),
            self::paymentMethodDefaultEnabled($method)
        );

        if ($method === self::METHOD_ONLINE) {
            return $enabled && self::onlineGatewayAvailable();
        }

        return $enabled;
    }

    public static function enabledPaymentMethodKeys(): array
    {
        $keys = array_values(array_filter(
            self::paymentMethodKeys(),
            fn (string $key) => self::isPaymentMethodEnabled($key)
        ));

        return $keys !== [] ? $keys : [self::METHOD_POS];
    }

    /**
     * گزینه‌های پرداخت فعال برای صفحه مشتری (با مشخصات کارت فروشگاه).
     */
    public static function paymentMethodsForApi(): array
    {
        $methods = [];
        foreach (self::enabledPaymentMethodKeys() as $key) {
            $row = [
                'key' => $key,
                'label' => self::paymentMethodLabel($key),
            ];
            if ($key === self::METHOD_CARD_TO_CARD) {
                $row['card_number'] = (string) Setting::get('shop_card_number', '');
                $row['card_holder'] = (string) Setting::get('shop_card_holder', '');
                $row['bank_name'] = (string) Setting::get('shop_bank_name', '');
                $row['receipt_required'] = false;
            }
            if ($key === self::METHOD_ONLINE) {
                $row['gateway'] = 'zarinpal';
            }
            $methods[] = $row;
        }

        return $methods;
    }

    /** مبلغ قابل پرداخت (تومان) پس از کسر اعتبار مشتری. */
    public function payableAmountToman(): int
    {
        $total = (float) $this->total_amount;
        $credit = 0.0;
        if ($this->use_credit && $this->phone) {
            $user = UserShiksho::query()
                ->where('atelier_id', $this->atelier_id)
                ->where('phone', $this->phone)
                ->first();
            if ($user && (float) $user->credit > 0) {
                $credit = \App\Tools\PriceTools::roundToman(min((float) $user->credit, $total));
            }
        }

        return (int) max(0, \App\Tools\PriceTools::roundToman($total - $credit));
    }

    public function toPublicArray(): array
    {
        $this->loadMissing(['items.product', 'shopTable']);

        $items = $this->items->map(function (TableOrderItem $line) {
            return [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'produced_good_id' => $line->produced_good_id ?? null,
                'raw_material_id' => $line->raw_material_id ?? null,
                'name' => $line->displayName(),
                'quantity' => (float) $line->quantity,
                'sale_price' => (float) $line->sale_price,
                'size' => $line->size,
                'color' => $line->color,
            ];
        })->values()->all();

        $hasOnline = self::hasOnlineColumns();

        return [
            'id' => $this->id,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'total_amount' => (float) $this->total_amount,
            'use_credit' => (bool) $this->use_credit,
            'payment_method' => $this->payment_method,
            'payment_method_label' => self::paymentMethodLabel($this->payment_method),
            'has_receipt' => (bool) $this->receipt_path,
            'receipt_url' => $this->receiptUrl(),
            'phone' => $this->phone,
            'note' => $this->note,
            'table_label' => $this->table_label,
            'table_number' => $this->shopTable ? (int) $this->shopTable->table_number : null,
            'kind' => $this->shopTable ? $this->shopTable->kind : ShopTable::KIND_TABLE,
            'purchase_id' => $this->purchase_id,
            'paid_online' => $this->isPaidOnline(),
            'online_paid_at' => $hasOnline && $this->online_paid_at ? $this->online_paid_at->toDateTimeString() : null,
            'online_ref_id' => $hasOnline ? $this->online_ref_id : null,
            'awaiting_online_payment' => $this->isAwaitingOnlinePayment(),
            'cancelled_by' => $hasOnline ? $this->cancelled_by : null,
            'cancelled_at' => $hasOnline && $this->cancelled_at ? $this->cancelled_at->toDateTimeString() : null,
            'items' => $items,
        ];
    }

    public function receiptUrl(): ?string
    {
        if (! $this->receipt_path) {
            return null;
        }

        return url(Storage::url($this->receipt_path));
    }
}
