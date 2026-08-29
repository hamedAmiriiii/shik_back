<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Cheque;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Morilog\Jalali\Jalalian;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'amount', 'title', 'description', 'date', 'user_name', 'atelier_id', 'beneficiary_id', 'shop_account_id',
        'payment_method', 'payment_status', 'paid_at', 'cheque_id', 'image_path',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected $appends = [
        'payment_method_label',
        'payment_status_label',
        'image_url',
        'has_items',
        'amount_source',
    ];

    /**
     * حسابی (فروشگاه یا تنخواه) که مبلغ از آن پرداخت شده است.
     */
    public function shopAccount()
    {
        return $this->belongsTo(ShopAccount::class, 'shop_account_id');
    }

    /**
     * کاربری که از او خرید شده (باشگاه مشتریان همین فروشگاه).
     */
    public function beneficiary()
    {
        return $this->belongsTo(UserShiksho::class, 'beneficiary_id');
    }

    public function cheque()
    {
        return $this->belongsTo(Cheque::class, 'cheque_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function rawMaterialLots(): HasMany
    {
        return $this->hasMany(RawMaterialLot::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        $path = $this->attributes['image_path'] ?? null;
        if (! $path) {
            return null;
        }

        return Storage::url($path);
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        $method = $this->attributes['payment_method'] ?? 'account';
        if ($method === 'cheque') {
            return 'چکی';
        }
        if ($method === 'credit') {
            return 'نسیه';
        }

        return 'از حساب';
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return (($this->attributes['payment_status'] ?? 'paid') === 'unpaid') ? 'پرداخت‌نشده' : 'پرداخت‌شده';
    }

    public function getDateAttribute($value): string
    {
        return Jalalian::fromDateTime($value)->format('Y-m-d');
    }

    public function getHasItemsAttribute(): bool
    {
        return $this->hasLineItems();
    }

    /**
     * items = مبلغ از مجموع آیتم‌ها | manual = مبلغ کلی بدون آیتم
     */
    public function getAmountSourceAttribute(): string
    {
        return $this->hasLineItems() ? 'items' : 'manual';
    }

    public function hasLineItems(): bool
    {
        if ($this->relationLoaded('items')) {
            return $this->items->isNotEmpty();
        }

        return $this->items()->exists();
    }

    public function itemsSum(): float
    {
        if ($this->relationLoaded('items')) {
            return round((float) $this->items->sum('total'), 2);
        }

        return round((float) $this->items()->sum('total'), 2);
    }

    /**
     * اگر فاکتور آیتم دارد، مبلغ کل را با مجموع آیتم‌ها یکی می‌کند.
     */
    public function syncAmountFromItems(): void
    {
        if (! $this->hasLineItems()) {
            return;
        }

        $sum = $this->itemsSum();
        if (round((float) $this->amount, 2) !== $sum) {
            $this->update(['amount' => $sum]);
        }
    }

    public function getCreatedAtAttribute($value): string
    {
        if (!$value) {
            return null;
        }
        $carbon = \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran');
        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }

    public function getUpdatedAtAttribute($value): string
    {
        if (!$value) {
            return null;
        }
        $carbon = \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran');
        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }
}

