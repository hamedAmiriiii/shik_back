<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPlan extends Model
{
    public const PRODUCT_CLASS = 'class';

    protected $fillable = [
        'product_slug',
        'name',
        'max_users',
        'max_videos',
        'duration_days',
        'duration_label',
        'price_rial',
        'features',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'max_users' => 'integer',
        'max_videos' => 'integer',
        'duration_days' => 'integer',
        'price_rial' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForProduct($query, string $slug)
    {
        return $query->where('product_slug', $slug);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function yadinoCatalog(): array
    {
        $durations = [
            ['days' => 180, 'label' => 'شش‌ماهه', 'key' => 'semiannual'],
            ['days' => 365, 'label' => 'یک‌ساله', 'key' => 'yearly'],
        ];

        $tiers = [
            ['users' => 5, 'name' => 'سرویس آموزشی ۵ کاربره', 'monthly' => 430000, 'quarterly' => 1290000, 'semiannual' => 2580000, 'yearly' => 5160000],
            ['users' => 30, 'name' => 'سرویس آموزشی ۳۰ کاربره', 'monthly' => 1560000, 'quarterly' => 4320000, 'semiannual' => 8160000, 'yearly' => 14640000],
            ['users' => 50, 'name' => 'سرویس آموزشی ۵۰ کاربره', 'monthly' => 2250000, 'quarterly' => 6120000, 'semiannual' => 11700000, 'yearly' => 21120000],
            ['users' => 100, 'name' => 'سرویس آموزشی ۱۰۰ کاربره', 'monthly' => 4270000, 'quarterly' => 11700000, 'semiannual' => 22440000, 'yearly' => 39600000],
            ['users' => 150, 'name' => 'سرویس آموزشی ۱۵۰ کاربره', 'monthly' => 6240000, 'quarterly' => 17100000, 'semiannual' => 32760000, 'yearly' => 58920000],
            ['users' => 250, 'name' => 'سرویس آموزشی ۲۵۰ کاربره', 'monthly' => 9650000, 'quarterly' => 26490000, 'semiannual' => 49980000, 'yearly' => 91320000],
            ['users' => 350, 'name' => 'سرویس آموزشی ۳۵۰ کاربره', 'monthly' => 13190000, 'quarterly' => 35700000, 'semiannual' => 68580000, 'yearly' => 124200000],
            ['users' => 500, 'name' => 'سرویس آموزشی ۵۰۰ کاربره', 'monthly' => 18580000, 'quarterly' => 50220000, 'semiannual' => 96420000, 'yearly' => 174360000],
        ];

        $rows = [];
        $sort = 1;
        foreach ($tiers as $tier) {
            $features = self::yadinoFeatures($tier['users']);
            foreach ($durations as $duration) {
                $toman = (int) round($tier[$duration['key']] * 0.95);
                $rows[] = [
                    'product_slug' => self::PRODUCT_CLASS,
                    'name' => $tier['name'],
                    'max_users' => $tier['users'],
                    'max_videos' => 4,
                    'duration_days' => $duration['days'],
                    'duration_label' => $duration['label'],
                    'price_rial' => $toman * 10,
                    'features' => $features,
                    'description' => implode('، ', $features),
                    'is_active' => true,
                    'sort_order' => $sort++,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public static function yadinoFeatures(int $users): array
    {
        $map = [
            5 => '۵',
            30 => '۳۰',
            50 => '۵۰',
            100 => '۱۰۰',
            150 => '۱۵۰',
            250 => '۲۵۰',
            350 => '۳۵۰',
            500 => '۵۰۰',
        ];
        $faUsers = $map[$users] ?? (string) $users;

        return [
            "تا {$faUsers} کاربر همزمان",
            'زمان برگزاری نامحدود',
            'تا ۴ ویدئو همزمان',
            'پیام‌رسان اختصاصی',
            'ایجاد چند کلاس و جلسه همزمان',
            'اشتراک‌گذاری تصویر',
            'اشتراک‌گذاری تخته',
        ];
    }
}
