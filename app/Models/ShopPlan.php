<?php

namespace App\Models;

use App\Support\ProjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class ShopPlan extends Model
{
    protected $fillable = [
        'name',
        'project_type',
        'duration_days',
        'price_rial',
        'discount_price_rial',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'duration_days' => 'integer',
        'price_rial' => 'integer',
        'discount_price_rial' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForProject($query, ?string $projectType)
    {
        if (! Schema::hasColumn('shop_plans', 'project_type')) {
            return $query;
        }
        $type = ProjectType::normalize($projectType);

        return $query->where('project_type', $type);
    }

    public function projectType(): string
    {
        if (! Schema::hasColumn('shop_plans', 'project_type')) {
            return ProjectType::SHOP;
        }

        return ProjectType::normalize($this->project_type ?? null);
    }

    /** مبلغ قابل پرداخت (با تخفیف اگر تعریف شده باشد) */
    public function payablePriceRial(): int
    {
        $discount = Schema::hasColumn('shop_plans', 'discount_price_rial')
            ? (int) ($this->discount_price_rial ?? 0)
            : 0;
        if ($discount > 0) {
            return $discount;
        }

        return (int) $this->price_rial;
    }
}
