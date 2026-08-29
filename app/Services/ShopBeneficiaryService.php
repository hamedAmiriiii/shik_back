<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\UserShiksho;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * ذینفع فاکتور/هزینه = کاربر باشگاه مشتریان (user_shiksho) که از او خرید شده.
 */
class ShopBeneficiaryService
{
    public static function supports(string $table): bool
    {
        return Schema::hasTable('user_shiksho')
            && Schema::hasColumn($table, 'beneficiary_id');
    }

    /**
     * @return array<string, string>
     */
    public static function requestRules(string $table): array
    {
        if (! self::supports($table)) {
            return [];
        }

        return [
            'beneficiary_id' => 'nullable|integer',
            'user_shiksho_id' => 'nullable|integer',
        ];
    }

    public static function findForShop(int $atelierId, int $id): ?UserShiksho
    {
        return UserShiksho::query()
            ->where('id', $id)
            ->where('atelier_id', $atelierId)
            ->first();
    }

    /**
     * beneficiary_id / user_shiksho_id را در $fields یکدست می‌کند.
     * اگر فیلد نفرستاده باشند و $onlyIfPresent=true باشد، از $fields حذف می‌شود.
     *
     * @return string|null پیام خطا
     */
    public static function applyToFields(int $atelierId, array &$fields, bool $onlyIfPresent = false, string $table = 'invoices'): ?string
    {
        if (! self::supports($table)) {
            unset($fields['beneficiary_id'], $fields['user_shiksho_id']);

            return null;
        }
        $present = array_key_exists('beneficiary_id', $fields)
            || array_key_exists('user_shiksho_id', $fields);

        if (! $present) {
            if ($onlyIfPresent) {
                unset($fields['beneficiary_id'], $fields['user_shiksho_id']);
            }

            return null;
        }

        $raw = array_key_exists('beneficiary_id', $fields)
            ? $fields['beneficiary_id']
            : $fields['user_shiksho_id'];
        unset($fields['user_shiksho_id']);

        if ($raw === null || $raw === '' || $raw === false) {
            $fields['beneficiary_id'] = null;

            return null;
        }

        $id = (int) $raw;
        if ($id <= 0) {
            $fields['beneficiary_id'] = null;

            return null;
        }

        if (! self::findForShop($atelierId, $id)) {
            return 'ذینفع انتخاب‌شده متعلق به این فروشگاه نیست.';
        }

        $fields['beneficiary_id'] = $id;

        return null;
    }

    public static function hideSensitive(?UserShiksho $user): void
    {
        if (! $user) {
            return;
        }

        $user->makeHidden([
            'credit',
            'installment_credit',
            'credit_last_updated_at',
            'last_warning_sent_at',
            'atelier_id',
        ]);
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    public static function attachTo(Model $model): Model
    {
        if (! self::supports($model->getTable())) {
            return $model;
        }

        $model->loadMissing('beneficiary');
        self::hideSensitive($model->beneficiary);

        return $model;
    }

    /**
     * @return array<string, float|int>
     */
    public static function totalsFor(UserShiksho $user): array
    {
        $atelierId = (int) $user->atelier_id;
        $id = (int) $user->id;

        $invoices = self::documentTotals(Invoice::query(), $atelierId, $id, 'invoices');
        $expenses = self::documentTotals(Expense::query(), $atelierId, $id, 'expenses');

        $purchased = round($invoices['total'] + $expenses['total'], 2);
        $unpaid = round($invoices['unpaid'] + $expenses['unpaid'], 2);

        return [
            'purchased_total' => $purchased,
            'paid_total' => round($purchased - $unpaid, 2),
            'unpaid_total' => $unpaid,
            'invoice_total' => $invoices['total'],
            'expense_total' => $expenses['total'],
            'unpaid_invoice_total' => $invoices['unpaid'],
            'unpaid_expense_total' => $expenses['unpaid'],
            'invoice_count' => $invoices['count'],
            'expense_count' => $expenses['count'],
            'unpaid_invoice_count' => $invoices['unpaid_count'],
            'unpaid_expense_count' => $expenses['unpaid_count'],
        ];
    }

    /**
     * @return array{total: float, unpaid: float, count: int, unpaid_count: int}
     */
    protected static function documentTotals(Builder $query, int $atelierId, int $beneficiaryId, string $table): array
    {
        if (! self::supports($table)) {
            return ['total' => 0.0, 'unpaid' => 0.0, 'count' => 0, 'unpaid_count' => 0];
        }

        $base = $query->where('atelier_id', $atelierId)->where('beneficiary_id', $beneficiaryId);
        $total = round((float) (clone $base)->sum('amount'), 2);
        $count = (int) (clone $base)->count();

        if (! Schema::hasColumn($table, 'payment_status')) {
            return [
                'total' => $total,
                'unpaid' => 0.0,
                'count' => $count,
                'unpaid_count' => 0,
            ];
        }

        $unpaidQuery = (clone $base)->where('payment_status', DocumentPaymentService::STATUS_UNPAID);

        return [
            'total' => $total,
            'unpaid' => round((float) (clone $unpaidQuery)->sum('amount'), 2),
            'count' => $count,
            'unpaid_count' => (int) (clone $unpaidQuery)->count(),
        ];
    }
}
