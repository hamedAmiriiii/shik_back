<?php

namespace App\Services;

/**
 * دسترسی‌های پنل فروشگاه — از روی APIهای فروشگاه، نه نقش‌های عروسی/ادمین.
 */
class ShopPermissionCatalog
{
    /**
     * مسیرهایی که کارمند لاگین‌شده همیشه می‌تواند بزند (پروفایل و لیست دسترسی).
     *
     * @var array<int, string>
     */
    public const ALWAYS_ALLOWED_PREFIXES = [
        'user',
        'shop-access',
        'shop-permissions',
        'beneficiaries',
    ];

    /**
     * پیشوندهایی که مال فروشگاه نیستند (نقش‌های قدیمی عروسی/ادمین).
     *
     * @var array<int, string>
     */
    public const SKIP_PREFIXES = [
        'admin',
        'cameraman',
        'atelier',
        'auth',
        'geo',
        'customer-register',
        'customer',
        'cart',
        'customer-addresses',
        'referrals',
        'reset-password',
        'confirmation-code',
        'agency-requests',
    ];

    /**
     * @return array<int, array{key: string, label: string, prefixes: array<int, string>}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'داشبورد', 'prefixes' => ['dashboard']],
            ['key' => 'pos', 'label' => 'فروش صندوق', 'prefixes' => ['purchased-products', 'pos-catalog']],
            ['key' => 'products', 'label' => 'کالاها', 'prefixes' => ['products', 'product']],
            ['key' => 'categories', 'label' => 'دسته‌بندی', 'prefixes' => ['category']],
            ['key' => 'manufacturers', 'label' => 'تولیدکننده', 'prefixes' => ['manufacturers']],
            ['key' => 'customers', 'label' => 'مشتریان', 'prefixes' => ['customers', 'customer-broadcast']],
            ['key' => 'invoices', 'label' => 'فاکتورها', 'prefixes' => ['invoices']],
            ['key' => 'expenses', 'label' => 'هزینه‌ها', 'prefixes' => ['expenses-statistics', 'expenses']],
            ['key' => 'cheques', 'label' => 'چک‌ها', 'prefixes' => ['cheques']],
            ['key' => 'manual_trades', 'label' => 'خرید و فروش دستی', 'prefixes' => ['manual-trades']],
            ['key' => 'raw_materials', 'label' => 'مواد اولیه', 'prefixes' => ['raw-materials']],
            ['key' => 'produced_goods', 'label' => 'کالای تولیدی', 'prefixes' => ['produced-goods']],
            ['key' => 'shop_accounts', 'label' => 'حساب‌ها و تنخواه', 'prefixes' => ['shop-account-transfers', 'shop-accounts']],
            ['key' => 'daily_reconciliations', 'label' => 'تطبیق روزانه', 'prefixes' => ['daily-reconciliations']],
            ['key' => 'employees', 'label' => 'کارمندان و حقوق', 'prefixes' => ['shop-employees', 'employee-payrolls']],
            ['key' => 'shop_tables', 'label' => 'میز و سفارش میز', 'prefixes' => ['shop-tables', 'table-orders']],
            ['key' => 'settings', 'label' => 'تنظیمات فروشگاه', 'prefixes' => ['settings']],
            ['key' => 'reports', 'label' => 'گزارش مالی', 'prefixes' => ['financial-report', 'reports']],
            ['key' => 'accounting', 'label' => 'حسابداری', 'prefixes' => ['accounting']],
            ['key' => 'shop_sms', 'label' => 'پیامک فروشگاه', 'prefixes' => ['shop-sms-logs', 'shop-sms-quota', 'sms-package-orders', 'sms-packages', 'sms-logs']],
            ['key' => 'backup', 'label' => 'پشتیبان‌گیری', 'prefixes' => ['shop-backup']],
            ['key' => 'online_orders', 'label' => 'سفارش آنلاین', 'prefixes' => ['orders']],
            ['key' => 'returns', 'label' => 'مرجوعی', 'prefixes' => ['returned-products', 'purchase-item-returns']],
            ['key' => 'debts', 'label' => 'نسیه و بدهی', 'prefixes' => ['purchase-debts']],
            ['key' => 'installments', 'label' => 'اقساط', 'prefixes' => ['installment-credits', 'installments']],
            ['key' => 'referral', 'label' => 'معرفی فروشگاه', 'prefixes' => ['referral']],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $item) {
            $out[] = [
                'key' => $item['key'],
                'label' => $item['label'],
            ];
        }

        return $out;
    }

    public static function isKnownKey(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    public static function labelFor(string $key): string
    {
        foreach (self::all() as $item) {
            if ($item['key'] === $key) {
                return $item['label'];
            }
        }

        return 'این بخش';
    }

    /**
     * پیام خطای ۴۰۳ متناسب با همان بخش و نوع درخواست.
     *
     * @return array{message: string, error: string, permission: string, permission_label: string}
     */
    public static function deniedPayload(string $key, string $method = 'GET'): array
    {
        $label = self::labelFor($key);
        $action = self::actionLabel($method);
        $message = 'شما اجازهٔ '.$action.' بخش «'.$label.'» را ندارید.';

        return [
            'message' => $message,
            'error' => $message,
            'permission' => $key,
            'permission_label' => $label,
        ];
    }

    public static function actionLabel(string $method): string
    {
        switch (strtoupper($method)) {
            case 'POST':
                return 'ثبت در';
            case 'PUT':
            case 'PATCH':
                return 'ویرایش';
            case 'DELETE':
                return 'حذف در';
            default:
                return 'مشاهده';
        }
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    public static function sanitize(array $keys): array
    {
        $allowed = array_flip(self::keys());
        $out = [];
        foreach ($keys as $key) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }
            $key = trim((string) $key);
            if ($key !== '' && isset($allowed[$key])) {
                $out[$key] = $key;
            }
        }

        return array_values($out);
    }

    /**
     * مسیر API فروشگاه (بدون پیشوند api/).
     */
    public static function permissionForPath(string $path): ?string
    {
        $path = self::normalizePath($path);
        if ($path === '' || self::pathStartsWith($path, self::ALWAYS_ALLOWED_PREFIXES) || self::pathStartsWith($path, self::SKIP_PREFIXES)) {
            return null;
        }

        $best = null;
        $bestLen = -1;
        foreach (self::all() as $item) {
            foreach ($item['prefixes'] as $prefix) {
                if (! self::pathMatchesPrefix($path, $prefix)) {
                    continue;
                }
                $len = strlen($prefix);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $item['key'];
                }
            }
        }

        return $best;
    }

    public static function normalizePath(string $path): string
    {
        $path = strtolower(trim($path, '/'));
        if (strpos($path, 'api/') === 0) {
            $path = substr($path, 4);
        }

        return $path;
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    public static function pathStartsWith(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (self::pathMatchesPrefix($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function pathMatchesPrefix(string $path, string $prefix): bool
    {
        if ($path === $prefix) {
            return true;
        }

        $len = strlen($prefix);

        return strlen($path) > $len && strpos($path, $prefix) === 0 && in_array($path[$len], ['/', '-', '?'], true);
    }
}
