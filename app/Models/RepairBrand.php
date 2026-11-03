<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RepairBrand extends Model
{
    public const DEFAULTS = [
        'سامسونگ',
        'ال‌جی',
        'بوش',
        'دوو',
        'پاکشوما',
        'اسنوا',
        'امرسان',
        'هیمالیا',
        'آبسال',
        'حایر',
        'کنوود',
        'فیلور',
        'زیرووات',
        'مابه',
        'سایر',
    ];

    protected $table = 'repair_brands';

    protected $fillable = ['name', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(RepairRequest::class, 'brand_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
