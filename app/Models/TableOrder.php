<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class TableOrder extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    public const METHOD_ONLINE = 'online';
    public const METHOD_CARD_TO_CARD = 'card_to_card';
    public const METHOD_POS = 'pos';

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
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'use_credit' => 'boolean',
    ];

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

    /**
     * گزینه‌های پرداخت برای صفحه مشتری (با مشخصات کارت فروشگاه).
     */
    public static function paymentMethodsForApi(): array
    {
        $cardToCard = [
            'key' => self::METHOD_CARD_TO_CARD,
            'label' => self::paymentMethodLabel(self::METHOD_CARD_TO_CARD),
            'card_number' => (string) Setting::get('shop_card_number', ''),
            'card_holder' => (string) Setting::get('shop_card_holder', ''),
            'bank_name' => (string) Setting::get('shop_bank_name', ''),
            'receipt_required' => false,
        ];

        return [
            [
                'key' => self::METHOD_ONLINE,
                'label' => self::paymentMethodLabel(self::METHOD_ONLINE),
            ],
            $cardToCard,
            [
                'key' => self::METHOD_POS,
                'label' => self::paymentMethodLabel(self::METHOD_POS),
            ],
        ];
    }

    public function toPublicArray(): array
    {
        $this->loadMissing(['items.product', 'shopTable']);

        $items = $this->items->map(function (TableOrderItem $line) {
            return [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'name' => optional($line->product)->name,
                'quantity' => (float) $line->quantity,
                'sale_price' => (float) $line->sale_price,
                'size' => $line->size,
                'color' => $line->color,
            ];
        })->values()->all();

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
            'purchase_id' => $this->purchase_id,
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
