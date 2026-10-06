<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * تم منوی سفارش پای میز (QR) که مدیر فروشگاه در تنظیمات انتخاب می‌کند.
 */
class ReservMenuTheme
{
    public const THEME_KEY = 'reserv_menu_theme';

    public const BG_MEDIA_KEY = 'reserv_menu_bg_media';

    public const BG_MEDIA_TYPE_KEY = 'reserv_menu_bg_media_type';

    /** آیکون فروشگاه در هدر منوی میز */
    public const ICON_MEDIA_KEY = 'shop_menu_icon';

    public const DEFAULT_THEME = 'classic';

    public const THEMES = ['classic', 'list', 'showcase', 'grid', 'cover', 'motion', 'video'];

    public const MANAGED_KEYS = [self::BG_MEDIA_KEY, self::BG_MEDIA_TYPE_KEY, self::ICON_MEDIA_KEY];

    public const BG_MAX_BYTES = 15 * 1024 * 1024;

    public const ICON_MAX_BYTES = 2 * 1024 * 1024;

    public static function normalize(?string $theme): string
    {
        $theme = strtolower(trim((string) $theme));

        return in_array($theme, self::THEMES, true) ? $theme : self::DEFAULT_THEME;
    }

    /**
     * فرض: زمینهٔ فروشگاه با Setting::setShopContext ست شده است.
     */
    public static function forApi(): array
    {
        $path = trim((string) Setting::get(self::BG_MEDIA_KEY, ''));
        $type = (string) Setting::get(self::BG_MEDIA_TYPE_KEY, '');
        $iconPath = trim((string) Setting::get(self::ICON_MEDIA_KEY, ''));

        return [
            'id' => self::normalize(Setting::get(self::THEME_KEY, self::DEFAULT_THEME)),
            'background_url' => $path !== '' ? Storage::url($path) : null,
            'background_type' => $path !== '' ? ($type === 'image' ? 'image' : 'video') : null,
            'icon_url' => $iconPath !== '' ? Storage::url($iconPath) : null,
        ];
    }
}
