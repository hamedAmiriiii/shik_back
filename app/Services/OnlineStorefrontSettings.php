<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * ظاهر ویترین فروشگاه آنلاین (لوگو، رنگ، بنر، بلوک‌ها، اعتماد).
 */
class OnlineStorefrontSettings
{
    public const KEY = 'online_storefront';

    public const LOGO_MEDIA_KEY = 'online_storefront_logo';

    public const MANAGED_KEYS = [self::LOGO_MEDIA_KEY];

    public const LOGO_MAX_BYTES = 2 * 1024 * 1024;

    public const BANNER_MAX_BYTES = 4 * 1024 * 1024;

    public const BLOCKS = [
        'hero_slider',
        'categories',
        'trust',
        'best_selling',
        'latest',
    ];

    public const DEFAULT_PRIMARY = '#ef4056';

    public const CARD_STYLES = ['digikala', 'soft'];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'logo_url' => null,
            'primary_color' => self::DEFAULT_PRIMARY,
            'banners' => [],
            'block_order' => self::BLOCKS,
            'hidden_category_ids' => [],
            'show_header_categories' => true,
            'show_category_row' => true,
            'product_card_style' => 'digikala',
            'trust_items' => [
                ['title' => 'ارسال سریع', 'subtitle' => 'تحویل به‌موقع سفارش', 'icon' => 'shipping'],
                ['title' => 'پرداخت امن', 'subtitle' => 'درگاه معتبر بانکی', 'icon' => 'payment'],
                ['title' => 'ضمانت اصالت', 'subtitle' => 'کالای اصل فروشگاه', 'icon' => 'verified'],
                ['title' => 'پشتیبانی', 'subtitle' => 'همراه شما هستیم', 'icon' => 'support'],
            ],
        ];
    }

    /**
     * فرض: Setting::setShopContext ست شده است.
     *
     * @return array<string, mixed>
     */
    public static function forApi(): array
    {
        $raw = Setting::get(self::KEY, null);
        $decoded = [];
        if (is_string($raw) && $raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        } elseif (is_array($raw)) {
            $decoded = $raw;
        }

        $config = self::normalize($decoded);

        $logoPath = trim((string) Setting::get(self::LOGO_MEDIA_KEY, ''));
        if ($logoPath !== '') {
            $config['logo_url'] = Storage::url($logoPath);
            $config['logo_path'] = $logoPath;
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $defaults = self::defaults();

        $color = trim((string) ($input['primary_color'] ?? $defaults['primary_color']));
        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = self::DEFAULT_PRIMARY;
        }

        $blockOrder = [];
        $incomingBlocks = $input['block_order'] ?? $defaults['block_order'];
        if (is_array($incomingBlocks)) {
            foreach ($incomingBlocks as $block) {
                $id = is_string($block) ? $block : '';
                if (in_array($id, self::BLOCKS, true) && ! in_array($id, $blockOrder, true)) {
                    $blockOrder[] = $id;
                }
            }
        }
        foreach (self::BLOCKS as $block) {
            if (! in_array($block, $blockOrder, true)) {
                $blockOrder[] = $block;
            }
        }

        $hidden = [];
        if (is_array($input['hidden_category_ids'] ?? null)) {
            foreach ($input['hidden_category_ids'] as $id) {
                $n = (int) $id;
                if ($n > 0 && ! in_array($n, $hidden, true)) {
                    $hidden[] = $n;
                }
            }
        }

        $banners = [];
        if (is_array($input['banners'] ?? null)) {
            foreach (array_slice($input['banners'], 0, 8) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $image = trim((string) ($row['image_url'] ?? ''));
                if ($image === '') {
                    continue;
                }
                $banners[] = [
                    'image_url' => mb_substr($image, 0, 500),
                    'link' => mb_substr(trim((string) ($row['link'] ?? '')), 0, 500),
                    'title' => mb_substr(trim((string) ($row['title'] ?? '')), 0, 120),
                ];
            }
        }

        $trust = [];
        if (is_array($input['trust_items'] ?? null)) {
            foreach (array_slice($input['trust_items'], 0, 6) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $icon = trim((string) ($row['icon'] ?? 'shipping'));
                if (! in_array($icon, ['shipping', 'payment', 'verified', 'support', 'return', 'original'], true)) {
                    $icon = 'shipping';
                }
                $trust[] = [
                    'title' => mb_substr($title, 0, 80),
                    'subtitle' => mb_substr(trim((string) ($row['subtitle'] ?? '')), 0, 120),
                    'icon' => $icon,
                ];
            }
        }
        if ($trust === []) {
            $trust = $defaults['trust_items'];
        }

        $logoUrl = null;
        if (! empty($input['logo_url']) && is_string($input['logo_url'])) {
            $logoUrl = mb_substr(trim($input['logo_url']), 0, 500);
            if ($logoUrl === '') {
                $logoUrl = null;
            }
        }

        $cardStyle = trim((string) ($input['product_card_style'] ?? $defaults['product_card_style']));
        if (! in_array($cardStyle, self::CARD_STYLES, true)) {
            $cardStyle = 'digikala';
        }

        $asBool = static function ($value, bool $default): bool {
            if ($value === null) {
                return $default;
            }
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int) $value === 1;
            }
            $s = strtolower(trim((string) $value));

            return in_array($s, ['1', 'true', 'yes', 'on'], true);
        };

        return [
            'logo_url' => $logoUrl,
            'primary_color' => strtolower($color),
            'banners' => $banners,
            'block_order' => $blockOrder,
            'hidden_category_ids' => $hidden,
            'show_header_categories' => $asBool(
                $input['show_header_categories'] ?? null,
                (bool) $defaults['show_header_categories']
            ),
            'show_category_row' => $asBool(
                $input['show_category_row'] ?? null,
                (bool) $defaults['show_category_row']
            ),
            'product_card_style' => $cardStyle,
            'trust_items' => $trust,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function save(array $input): array
    {
        $config = self::normalize($input);
        // مسیر لوگو در کلید جداست؛ فقط URL عمومی در JSON نگه داشته نمی‌شود مگر لینک خارجی
        $stored = $config;
        unset($stored['logo_path']);
        $logoPath = trim((string) Setting::get(self::LOGO_MEDIA_KEY, ''));
        if ($logoPath !== '') {
            $stored['logo_url'] = null;
        }
        Setting::set(self::KEY, json_encode($stored, JSON_UNESCAPED_UNICODE));

        return self::forApi();
    }
}
