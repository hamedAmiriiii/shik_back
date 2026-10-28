<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingPostingMap;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class AccountingPostingMapService
{
    /** @var array<int, array<string, int>> */
    private static $overrideCache = [];

    public static function clearCache(?int $atelierId = null): void
    {
        if ($atelierId) {
            unset(self::$overrideCache[$atelierId]);
            Cache::forget(self::cacheKey($atelierId));

            return;
        }
        self::$overrideCache = [];
    }

    /**
     * شناسه حساب برای یک رویداد نرم‌افزار (با امکان override حسابدار).
     */
    public static function accountId(int $atelierId, string $eventKey): int
    {
        $override = self::overrideAccountId($atelierId, $eventKey);
        if ($override) {
            return $override;
        }

        $code = AccountingPostingMapRegistry::defaultCode($eventKey);
        if (! $code) {
            throw new RuntimeException('نقش حسابداری «'.$eventKey.'» تعریف نشده است.');
        }

        return AccountingLedger::accountId($atelierId, $code);
    }

    public static function overrideAccountId(int $atelierId, string $eventKey): ?int
    {
        if ($atelierId <= 0 || ! AccountingPostingMap::tableReady()) {
            return null;
        }
        $map = self::overridesForAtelier($atelierId);

        return $map[$eventKey] ?? null;
    }

    /**
     * @return array<string, int> event_key => account_id
     */
    public static function overridesForAtelier(int $atelierId): array
    {
        if (isset(self::$overrideCache[$atelierId])) {
            return self::$overrideCache[$atelierId];
        }

        $cached = Cache::get(self::cacheKey($atelierId));
        if (is_array($cached)) {
            return self::$overrideCache[$atelierId] = $cached;
        }

        $rows = AccountingPostingMap::query()
            ->where('atelier_id', $atelierId)
            ->pluck('account_id', 'event_key')
            ->all();
        $map = [];
        foreach ($rows as $key => $accountId) {
            $map[(string) $key] = (int) $accountId;
        }
        Cache::put(self::cacheKey($atelierId), $map, 300);
        self::$overrideCache[$atelierId] = $map;

        return $map;
    }

    /**
     * لیست کامل نقش‌ها با حساب فعلی (پیش‌فرض یا ویرایش‌شده).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalogForAtelier(int $atelierId): array
    {
        ChartOfAccountsSeeder::ensureForAtelier($atelierId);
        $overrides = self::overridesForAtelier($atelierId);
        $accountsById = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->get(['id', 'code', 'name', 'level', 'kind', 'is_active'])
            ->keyBy('id');
        $accountsByCode = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->get(['id', 'code', 'name', 'level', 'kind', 'is_active'])
            ->keyBy('code');

        $out = [];
        foreach (AccountingPostingMapRegistry::all() as $row) {
            $overrideId = $overrides[$row['key']] ?? null;
            $account = null;
            $isCustom = false;
            if ($overrideId && $accountsById->has($overrideId)) {
                $account = $accountsById->get($overrideId);
                $isCustom = true;
            } elseif ($accountsByCode->has($row['default_code'])) {
                $account = $accountsByCode->get($row['default_code']);
            }

            $out[] = [
                'event_key' => $row['key'],
                'group' => $row['group'],
                'group_label' => $row['group_label'],
                'label' => $row['label'],
                'description' => $row['description'],
                'default_code' => $row['default_code'],
                'is_custom' => $isCustom,
                'account_id' => $account ? (int) $account->id : null,
                'account' => $account ? [
                    'id' => (int) $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'level' => $account->level,
                    'kind' => $account->kind,
                    'is_active' => (bool) $account->is_active,
                ] : null,
            ];
        }

        return $out;
    }

    public static function setMap(int $atelierId, string $eventKey, ?int $accountId): void
    {
        if (! AccountingPostingMap::tableReady()) {
            throw new RuntimeException('جدول نگاشت حسابداری وجود ندارد. SQL را اجرا کنید.');
        }
        $meta = AccountingPostingMapRegistry::find($eventKey);
        if (! $meta) {
            throw new RuntimeException('نقش حسابداری نامعتبر است.');
        }

        if ($accountId === null || $accountId <= 0) {
            AccountingPostingMap::query()
                ->where('atelier_id', $atelierId)
                ->where('event_key', $eventKey)
                ->delete();
            self::clearCache($atelierId);

            return;
        }

        $account = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->whereKey($accountId)
            ->first();
        if (! $account) {
            throw new RuntimeException('حساب انتخاب‌شده متعلق به این فروشگاه نیست.');
        }
        if (! in_array($account->level, [AccountingAccount::LEVEL_TAFSILI, AccountingAccount::LEVEL_MOEIN], true)) {
            throw new RuntimeException('فقط حساب معین یا تفصیلی قابل انتخاب است.');
        }

        AccountingPostingMap::query()->updateOrCreate(
            ['atelier_id' => $atelierId, 'event_key' => $eventKey],
            ['account_id' => $accountId]
        );
        self::clearCache($atelierId);
    }

    private static function cacheKey(int $atelierId): string
    {
        return 'accounting_posting_maps:'.$atelierId;
    }
}
