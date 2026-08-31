<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\ShopAccount;
use Illuminate\Support\Facades\Schema;

class ChartOfAccountsSeeder
{
    public const CODE_TILL = '11101';

    public const CODE_ACCOUNT_1 = '11111';

    public const CODE_ACCOUNT_2 = '11112';

    public const CODE_PETTY_TEMPLATE = '11120';

    public const CODE_CASH_MOEIN = '111';

    public const CODE_AR = '11201';

    public const CODE_CHEQUE_RECEIVABLE = '11401';

    public const CODE_INV_CATALOG = '11301';

    public const CODE_INV_RAW = '11302';

    public const CODE_INV_FINISHED = '11303';

    public const CODE_REVENUE = '411';

    public const CODE_DISCOUNT = '412';

    public const CODE_LOYALTY = '613';

    public const CODE_COGS = '511';

    public const CODE_AP = '21101';

    public const CODE_CHEQUE_PAYABLE = '21201';

    public const CODE_CAPEX = '12101';

    public const CODE_EXPENSE = '611';

    public const CODE_PAYROLL = '612';

    public const CODE_OTHER_INCOME = '431';

    public const CODE_EQUITY = '311';

    /**
     * درخت قفل‌شدهٔ نقشه راه. parent_code تهی = ریشه.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function blueprint(): array
    {
        $d = AccountingAccount::NATURE_DEBIT;
        $c = AccountingAccount::NATURE_CREDIT;
        $g = AccountingAccount::LEVEL_GROUP;
        $k = AccountingAccount::LEVEL_KOL;
        $m = AccountingAccount::LEVEL_MOEIN;
        $t = AccountingAccount::LEVEL_TAFSILI;

        return [
            ['code' => '1', 'parent' => null, 'name' => 'دارایی‌ها', 'level' => $g, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '11', 'parent' => '1', 'name' => 'دارایی جاری', 'level' => $k, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '12', 'parent' => '1', 'name' => 'دارایی غیرجاری', 'level' => $k, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '111', 'parent' => '11', 'name' => 'موجودی نقد و بانک', 'level' => $m, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '11101', 'parent' => '111', 'name' => 'صندوق فروش (وجوه در راه)', 'level' => $t, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '11111', 'parent' => '111', 'name' => 'حساب ۱', 'level' => $t, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '11112', 'parent' => '111', 'name' => 'حساب ۲', 'level' => $t, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '11120', 'parent' => '111', 'name' => 'تنخواه', 'level' => $t, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '112', 'parent' => '11', 'name' => 'حساب‌های دریافتنی', 'level' => $m, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '11201', 'parent' => '112', 'name' => 'مشتریان', 'level' => $t, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '113', 'parent' => '11', 'name' => 'موجودی کالا', 'level' => $m, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '11301', 'parent' => '113', 'name' => 'کالای کاتالوگ', 'level' => $t, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '11302', 'parent' => '113', 'name' => 'مواد اولیه', 'level' => $t, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '11303', 'parent' => '113', 'name' => 'کالای ساخته‌شده', 'level' => $t, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '114', 'parent' => '11', 'name' => 'اسناد دریافتنی (چک)', 'level' => $m, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '11401', 'parent' => '114', 'name' => 'چک‌های دریافتنی', 'level' => $t, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '121', 'parent' => '12', 'name' => 'اموال و تجهیزات', 'level' => $m, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '12101', 'parent' => '121', 'name' => 'دارایی سرمایه‌ای', 'level' => $t, 'nature' => $d, 'kind' => AccountingAccount::KIND_ASSET],
            ['code' => '2', 'parent' => null, 'name' => 'بدهی‌ها', 'level' => $g, 'nature' => $c, 'kind' => AccountingAccount::KIND_LIABILITY],
            ['code' => '21', 'parent' => '2', 'name' => 'بدهی جاری', 'level' => $k, 'nature' => $c, 'kind' => AccountingAccount::KIND_LIABILITY],
            ['code' => '211', 'parent' => '21', 'name' => 'حساب‌های پرداختنی', 'level' => $m, 'nature' => $c, 'kind' => AccountingAccount::KIND_LIABILITY],
            ['code' => '21101', 'parent' => '211', 'name' => 'تأمین‌کنندگان / ذی‌نفعان', 'level' => $t, 'nature' => $c, 'kind' => AccountingAccount::KIND_LIABILITY],
            ['code' => '212', 'parent' => '21', 'name' => 'اسناد پرداختنی (چک صادره)', 'level' => $m, 'nature' => $c, 'kind' => AccountingAccount::KIND_LIABILITY],
            ['code' => '21201', 'parent' => '212', 'name' => 'چک‌های پرداختنی', 'level' => $t, 'nature' => $c, 'kind' => AccountingAccount::KIND_LIABILITY],
            ['code' => '3', 'parent' => null, 'name' => 'حقوق مالکانه', 'level' => $g, 'nature' => $c, 'kind' => AccountingAccount::KIND_EQUITY],
            ['code' => '31', 'parent' => '3', 'name' => 'سرمایه', 'level' => $k, 'nature' => $c, 'kind' => AccountingAccount::KIND_EQUITY],
            ['code' => '311', 'parent' => '31', 'name' => 'سرمایه صاحب فروشگاه', 'level' => $m, 'nature' => $c, 'kind' => AccountingAccount::KIND_EQUITY],
            ['code' => '35', 'parent' => '3', 'name' => 'سود انباشته', 'level' => $k, 'nature' => $c, 'kind' => AccountingAccount::KIND_EQUITY],
            ['code' => '4', 'parent' => null, 'name' => 'درآمدها', 'level' => $g, 'nature' => $c, 'kind' => AccountingAccount::KIND_REVENUE],
            ['code' => '41', 'parent' => '4', 'name' => 'درآمد عملیاتی', 'level' => $k, 'nature' => $c, 'kind' => AccountingAccount::KIND_REVENUE],
            ['code' => '411', 'parent' => '41', 'name' => 'درآمد فروش کالا', 'level' => $m, 'nature' => $c, 'kind' => AccountingAccount::KIND_REVENUE],
            ['code' => '412', 'parent' => '41', 'name' => 'برگشت از فروش و تخفیفات', 'level' => $m, 'nature' => $d, 'kind' => AccountingAccount::KIND_REVENUE],
            ['code' => '43', 'parent' => '4', 'name' => 'سایر درآمدها', 'level' => $k, 'nature' => $c, 'kind' => AccountingAccount::KIND_REVENUE],
            ['code' => '431', 'parent' => '43', 'name' => 'درآمد متفرقه', 'level' => $m, 'nature' => $c, 'kind' => AccountingAccount::KIND_REVENUE],
            ['code' => '5', 'parent' => null, 'name' => 'بهای تمام‌شده', 'level' => $g, 'nature' => $d, 'kind' => AccountingAccount::KIND_COGS],
            ['code' => '51', 'parent' => '5', 'name' => 'بهای تمام‌شده کالای فروش‌رفته', 'level' => $k, 'nature' => $d, 'kind' => AccountingAccount::KIND_COGS],
            ['code' => '511', 'parent' => '51', 'name' => 'بهای تمام‌شده فروش', 'level' => $m, 'nature' => $d, 'kind' => AccountingAccount::KIND_COGS],
            ['code' => '6', 'parent' => null, 'name' => 'هزینه‌ها', 'level' => $g, 'nature' => $d, 'kind' => AccountingAccount::KIND_EXPENSE],
            ['code' => '61', 'parent' => '6', 'name' => 'هزینه‌های عملیاتی', 'level' => $k, 'nature' => $d, 'kind' => AccountingAccount::KIND_EXPENSE],
            ['code' => '611', 'parent' => '61', 'name' => 'هزینه جاری', 'level' => $m, 'nature' => $d, 'kind' => AccountingAccount::KIND_EXPENSE],
            ['code' => '612', 'parent' => '61', 'name' => 'هزینه حقوق و دستمزد', 'level' => $m, 'nature' => $d, 'kind' => AccountingAccount::KIND_EXPENSE],
            ['code' => '613', 'parent' => '61', 'name' => 'هزینه تخفیف / اعتبار وفاداری', 'level' => $m, 'nature' => $d, 'kind' => AccountingAccount::KIND_EXPENSE],
        ];
    }

    public static function ensureForAtelier(int $atelierId): void
    {
        if ($atelierId <= 0 || ! AccountingAccount::tableReady()) {
            return;
        }

        self::seedTree($atelierId);
        self::linkTill($atelierId);

        if (! Schema::hasTable('shop_accounts')) {
            return;
        }

        $accounts = ShopAccount::query()->forAtelier($atelierId)->orderBy('id')->get();
        foreach ($accounts as $account) {
            self::syncShopAccount($account);
        }
    }

    public static function syncShopAccount(ShopAccount $account): ?AccountingAccount
    {
        if (! AccountingAccount::tableReady() || (int) $account->atelier_id <= 0) {
            return null;
        }

        self::seedTree((int) $account->atelier_id);
        self::linkTill((int) $account->atelier_id);

        $existing = AccountingAccount::query()
            ->forAtelier((int) $account->atelier_id)
            ->where('linked_type', AccountingAccount::LINK_SHOP_ACCOUNT)
            ->where('linked_id', $account->id)
            ->first();

        if ($existing) {
            $existing->name = $account->name;
            $existing->is_active = (bool) $account->is_active;
            $existing->save();

            return $existing;
        }

        if ($account->legacy_slot === ShopAccount::LEGACY_ACCOUNT_1) {
            return self::linkCodeToShopAccount($account, self::CODE_ACCOUNT_1);
        }
        if ($account->legacy_slot === ShopAccount::LEGACY_ACCOUNT_2) {
            return self::linkCodeToShopAccount($account, self::CODE_ACCOUNT_2);
        }
        if ($account->isPettyCash()) {
            return self::allocatePettyCash($account);
        }

        return self::allocateExtraShopAccount($account);
    }

    /**
     * @return array<int, AccountingAccount>
     */
    public static function treeForAtelier(int $atelierId, bool $includeInactive = false)
    {
        self::ensureForAtelier($atelierId);

        $query = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->orderBy('code');

        if (! $includeInactive) {
            $query->active();
        }

        $all = $query->get();
        $byParent = $all->groupBy(fn (AccountingAccount $row) => $row->parent_id ?: 0);

        return $all
            ->filter(fn (AccountingAccount $row) => $row->parent_id === null)
            ->values()
            ->map(function (AccountingAccount $root) use ($byParent) {
                self::attachChildren($root, $byParent);

                return $root;
            })
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, AccountingAccount>>  $byParent
     */
    protected static function attachChildren(AccountingAccount $node, $byParent): void
    {
        $children = $byParent->get($node->id, collect())->values();
        $node->setRelation('children', $children);
        foreach ($children as $child) {
            self::attachChildren($child, $byParent);
        }
    }

    protected static function seedTree(int $atelierId): void
    {
        $ids = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->pluck('id', 'code');

        foreach (self::blueprint() as $node) {
            $parentId = $node['parent'] ? ($ids[$node['parent']] ?? null) : null;
            $row = AccountingAccount::query()->firstOrNew([
                'atelier_id' => $atelierId,
                'code' => $node['code'],
            ]);

            $row->parent_id = $parentId;
            $row->level = $node['level'];
            $row->nature = $node['nature'];
            $row->kind = $node['kind'];
            $row->is_system = true;
            if (! $row->exists || ! $row->linked_type) {
                $row->name = $node['name'];
            }
            if (! $row->exists) {
                $row->is_active = true;
            }
            $row->save();
            $ids[$node['code']] = $row->id;
        }
    }

    protected static function linkTill(int $atelierId): void
    {
        $till = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->where('code', self::CODE_TILL)
            ->first();
        if (! $till) {
            return;
        }

        $till->linked_type = AccountingAccount::LINK_TILL;
        $till->linked_id = null;
        $till->is_system = true;
        $till->save();
    }

    protected static function linkCodeToShopAccount(ShopAccount $account, string $code): ?AccountingAccount
    {
        $row = AccountingAccount::query()
            ->forAtelier((int) $account->atelier_id)
            ->where('code', $code)
            ->first();
        if (! $row) {
            return null;
        }

        $row->linked_type = AccountingAccount::LINK_SHOP_ACCOUNT;
        $row->linked_id = $account->id;
        $row->name = $account->name;
        $row->is_active = (bool) $account->is_active;
        $row->is_system = true;
        $row->save();

        return $row;
    }

    protected static function allocatePettyCash(ShopAccount $account): ?AccountingAccount
    {
        $template = AccountingAccount::query()
            ->forAtelier((int) $account->atelier_id)
            ->where('code', self::CODE_PETTY_TEMPLATE)
            ->first();

        if ($template && $template->linked_id === null) {
            return self::linkCodeToShopAccount($account, self::CODE_PETTY_TEMPLATE);
        }

        return self::createCashTafsili(
            $account,
            self::nextNumericCode((int) $account->atelier_id, 11121)
        );
    }

    protected static function allocateExtraShopAccount(ShopAccount $account): ?AccountingAccount
    {
        $code = self::nextNumericCode((int) $account->atelier_id, 11113, [[11120, 11129]]);

        return self::createCashTafsili($account, $code);
    }

    protected static function createCashTafsili(ShopAccount $account, string $code): ?AccountingAccount
    {
        $parent = AccountingAccount::query()
            ->forAtelier((int) $account->atelier_id)
            ->where('code', self::CODE_CASH_MOEIN)
            ->first();
        if (! $parent) {
            return null;
        }

        $row = new AccountingAccount();
        $row->atelier_id = (int) $account->atelier_id;
        $row->parent_id = $parent->id;
        $row->code = $code;
        $row->name = $account->name;
        $row->level = AccountingAccount::LEVEL_TAFSILI;
        $row->nature = AccountingAccount::NATURE_DEBIT;
        $row->kind = AccountingAccount::KIND_ASSET;
        $row->is_system = false;
        $row->linked_type = AccountingAccount::LINK_SHOP_ACCOUNT;
        $row->linked_id = $account->id;
        $row->is_active = (bool) $account->is_active;
        $row->save();

        return $row;
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $skipRanges
     */
    protected static function nextNumericCode(int $atelierId, int $start, array $skipRanges = []): string
    {
        $used = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->pluck('code')
            ->flip();

        $n = $start;
        while (true) {
            $skip = false;
            foreach ($skipRanges as $range) {
                if ($n >= $range[0] && $n <= $range[1]) {
                    $skip = true;
                    break;
                }
            }
            $code = (string) $n;
            if (! $skip && ! isset($used[$code])) {
                return $code;
            }
            $n++;
        }
    }
}
