<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\ShopAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;
use RuntimeException;

class DocumentPaymentService
{
    public const METHOD_ACCOUNT = 'account';

    public const METHOD_CHEQUE = 'cheque';

    public const METHOD_CREDIT = 'credit';

    public const STATUS_PAID = 'paid';

    public const STATUS_UNPAID = 'unpaid';

    public static function methods(): array
    {
        return [self::METHOD_ACCOUNT, self::METHOD_CHEQUE, self::METHOD_CREDIT];
    }

    /**
     * @return array<string, string>
     */
    public static function requestRules(): array
    {
        return [
            'payment_method' => 'nullable|in:account,cheque,credit',
            'cheque_id' => 'nullable|integer',
            'cheque' => 'nullable|array',
            'cheque.cheque_number' => 'required_with:cheque|string|max:64',
            'cheque.bank_name' => 'nullable|string|max:255',
            'cheque.payee' => 'nullable|string|max:255',
            'cheque.title' => 'nullable|string|max:255',
            'cheque.note' => 'nullable|string',
            'cheque.due_date' => 'required_with:cheque|array',
            'cheque.due_date.year' => 'required_with:cheque.due_date|integer',
            'cheque.due_date.month' => 'required_with:cheque.due_date|integer|min:1|max:12',
            'cheque.due_date.day' => 'required_with:cheque.due_date|integer|min:1|max:31',
            'cheque.issue_date' => 'nullable|array',
            'cheque.issue_date.year' => 'required_with:cheque.issue_date|integer',
            'cheque.issue_date.month' => 'required_with:cheque.issue_date|integer|min:1|max:12',
            'cheque.issue_date.day' => 'required_with:cheque.issue_date|integer|min:1|max:31',
        ];
    }

    public static function supports(Model $model): bool
    {
        return Schema::hasTable($model->getTable())
            && Schema::hasColumn($model->getTable(), 'payment_status');
    }

    public static function isPaid(Model $model): bool
    {
        if (! self::supports($model)) {
            return $model->shop_account_id !== null;
        }

        return ($model->payment_status ?? self::STATUS_PAID) === self::STATUS_PAID;
    }

    /**
     * فیلدهای پرداخت برای ثبت هزینه/فاکتور.
     *
     * @return array{payment_method: string, payment_status: string, paid_at: mixed, shop_account_id: mixed}
     */
    public static function resolveOnCreate(int $atelierId, array $fields, float $amount, string $table = 'expenses'): array
    {
        $method = $fields['payment_method'] ?? null;
        $shopAccountId = $fields['shop_account_id'] ?? null;

        if (! Schema::hasColumn($table, 'payment_status')) {
            if ($shopAccountId) {
                self::assertCanDebit($atelierId, (int) $shopAccountId, $amount);
            }

            return [
                'shop_account_id' => $shopAccountId ? (int) $shopAccountId : null,
            ];
        }

        if ($method === null || $method === '') {
            $method = $shopAccountId ? self::METHOD_ACCOUNT : self::METHOD_CREDIT;
        }

        if ($method === self::METHOD_ACCOUNT) {
            if (! $shopAccountId) {
                throw new RuntimeException('برای پرداخت از حساب باید حساب را انتخاب کنید.');
            }
            self::assertCanDebit($atelierId, (int) $shopAccountId, $amount);

            return [
                'payment_method' => self::METHOD_ACCOUNT,
                'payment_status' => self::STATUS_PAID,
                'paid_at' => now(),
                'shop_account_id' => (int) $shopAccountId,
            ];
        }

        if ($method === self::METHOD_CHEQUE) {
            return [
                'payment_method' => self::METHOD_CHEQUE,
                'payment_status' => self::STATUS_UNPAID,
                'paid_at' => null,
                'shop_account_id' => $shopAccountId ? (int) $shopAccountId : null,
            ];
        }

        return [
            'payment_method' => self::METHOD_CREDIT,
            'payment_status' => self::STATUS_UNPAID,
            'paid_at' => null,
            'shop_account_id' => $shopAccountId ? (int) $shopAccountId : null,
        ];
    }

    public static function settle(Model $model, int $shopAccountId): Model
    {
        if (! $model instanceof Expense && ! $model instanceof Invoice) {
            throw new RuntimeException('این سند قابل تسویه از حساب نیست.');
        }

        if (self::supports($model) && self::isPaid($model) && $model->shop_account_id) {
            throw new RuntimeException('این سند قبلاً از حساب پرداخت شده است.');
        }

        $atelierId = (int) $model->atelier_id;
        self::assertCanDebit($atelierId, $shopAccountId, (float) $model->amount, $model);

        $payload = [
            'shop_account_id' => $shopAccountId,
        ];
        if (self::supports($model)) {
            $payload['payment_status'] = self::STATUS_PAID;
            $payload['paid_at'] = now();
            if (! $model->payment_method || $model->payment_method === self::METHOD_ACCOUNT) {
                $payload['payment_method'] = $model->payment_method ?: self::METHOD_ACCOUNT;
            }
        }
        $model->update($payload);

        return $model->fresh();
    }

    /**
     * بعد از وصول چک: اگر موجودی کافی باشد از حساب کسر می‌شود.
     */
    public static function trySettleAfterClear(Model $model, ?int $shopAccountId): bool
    {
        if (! $shopAccountId) {
            return false;
        }
        if (self::supports($model) && self::isPaid($model) && $model->shop_account_id) {
            return true;
        }

        try {
            self::settle($model, $shopAccountId);

            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    public static function unpay(Model $model): void
    {
        if (! self::supports($model)) {
            return;
        }

        $model->update([
            'payment_status' => self::STATUS_UNPAID,
            'paid_at' => null,
        ]);
    }

    public static function assertCanDebit(int $atelierId, int $shopAccountId, float $amount, ?Model $ignorePaidDocument = null): void
    {
        $account = ShopAccount::find($shopAccountId);
        if (! $account || (int) $account->atelier_id !== $atelierId) {
            throw new RuntimeException('حساب انتخاب‌شده متعلق به این فروشگاه نیست.');
        }
        if (! $account->is_active) {
            throw new RuntimeException('حساب انتخاب‌شده غیرفعال است.');
        }

        $available = ShopAccountBalanceService::availableBalance($account, $ignorePaidDocument);
        if (round($amount, 2) - $available > 0.001) {
            throw new RuntimeException(
                'موجودی حساب «'.$account->name.'» کافی نیست. موجودی: '.number_format($available).' — مبلغ: '.number_format($amount)
            );
        }
    }

    public static function attachIssuedCheque(Model $model, array $chequeFields, string $userName): Cheque
    {
        $cheque = Cheque::create([
            'atelier_id' => (int) $model->atelier_id,
            'type' => Cheque::TYPE_ISSUED,
            'cheque_number' => $chequeFields['cheque_number'],
            'bank_name' => $chequeFields['bank_name'] ?? null,
            'payee' => $chequeFields['payee'] ?? null,
            'amount' => $model->amount,
            'issue_date' => $chequeFields['issue_date'] ?? null,
            'due_date' => $chequeFields['due_date'],
            'title' => $chequeFields['title'] ?? $model->title,
            'expense_type' => $model instanceof Expense ? ($model->type ?: 'جاری') : 'جاری',
            'status' => Cheque::STATUS_PENDING,
            'expense_id' => $model instanceof Expense ? $model->id : null,
            'invoice_id' => $model instanceof Invoice ? $model->id : null,
            'shop_account_id' => $model->shop_account_id,
            'user_name' => $userName,
            'note' => $chequeFields['note'] ?? null,
        ]);

        if (Schema::hasColumn($model->getTable(), 'cheque_id')) {
            $model->update(['cheque_id' => $cheque->id]);
        }

        return $cheque;
    }

    public static function linkExistingCheque(Model $model, int $chequeId): Cheque
    {
        $cheque = Cheque::find($chequeId);
        if (! $cheque || (int) $cheque->atelier_id !== (int) $model->atelier_id) {
            throw new RuntimeException('چک متعلق به این فروشگاه نیست.');
        }
        if ($cheque->type !== Cheque::TYPE_ISSUED) {
            throw new RuntimeException('فقط چک صادره را می‌توان به هزینه/فاکتور وصل کرد.');
        }
        if ($cheque->status !== Cheque::STATUS_PENDING) {
            throw new RuntimeException('فقط چک در انتظار وصول قابل اتصال است.');
        }
        if ($cheque->expense_id || $cheque->invoice_id || $cheque->purchase_id) {
            throw new RuntimeException('این چک قبلاً به سند دیگری وصل شده است.');
        }

        $payload = [
            'shop_account_id' => $model->shop_account_id ?: $cheque->shop_account_id,
            'amount' => $model->amount,
        ];
        if ($model instanceof Expense) {
            $payload['expense_id'] = $model->id;
            $payload['expense_type'] = $model->type ?: 'جاری';
        } else {
            $payload['invoice_id'] = $model->id;
        }
        $cheque->update($payload);

        if (Schema::hasColumn($model->getTable(), 'cheque_id')) {
            $model->update(['cheque_id' => $cheque->id]);
        }

        return $cheque->fresh();
    }

    public static function attachChequeFromRequest(Model $model, array $fields, string $userName): ?Cheque
    {
        if (($model->payment_method ?? '') !== self::METHOD_CHEQUE) {
            return null;
        }
        if (! empty($fields['cheque_id'])) {
            return self::linkExistingCheque($model, (int) $fields['cheque_id']);
        }
        if (empty($fields['cheque']) || ! is_array($fields['cheque'])) {
            throw new RuntimeException('برای پرداخت چکی مشخصات چک یا شناسه چک لازم است.');
        }

        $cheque = $fields['cheque'];
        $cheque['due_date'] = self::parseJalaliDate($cheque['due_date'] ?? null);
        $cheque['issue_date'] = self::parseJalaliDate($cheque['issue_date'] ?? null);
        if (! $cheque['due_date']) {
            throw new RuntimeException('تاریخ سررسید چک الزامی است.');
        }

        return self::attachIssuedCheque($model, $cheque, $userName);
    }

    public static function parseJalaliDate($parts): ?string
    {
        if (! is_array($parts) || empty($parts['year']) || empty($parts['month']) || empty($parts['day'])) {
            return null;
        }

        return (new Jalalian((int) $parts['year'], (int) $parts['month'], (int) $parts['day']))
            ->toCarbon()
            ->format('Y-m-d');
    }
}
