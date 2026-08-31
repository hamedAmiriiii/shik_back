<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\DocumentPayment;
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

    public const METHOD_MIXED = 'mixed';

    public const STATUS_PAID = 'paid';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIAL = 'partial';

    public static function methods(): array
    {
        return [self::METHOD_ACCOUNT, self::METHOD_CHEQUE, self::METHOD_CREDIT, self::METHOD_MIXED];
    }

    public static function supportsSplits(): bool
    {
        return Schema::hasTable('document_payments');
    }

    /**
     * @return array<string, string>
     */
    public static function requestRules(): array
    {
        $chequeShape = [
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

        return array_merge([
            'payment_method' => 'nullable|string|max:32',
            'cheque_id' => 'nullable|integer',
            'payments' => 'nullable|array',
            'payments.*.method' => 'required_with:payments|string|max:32',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.shop_account_id' => 'nullable|integer',
            'payments.*.cheque_id' => 'nullable|integer',
            'payments.*.cheque' => 'nullable|array',
            'cash_amount' => 'nullable|numeric|min:0',
            'cheque_amount' => 'nullable|numeric|min:0',
            'credit_amount' => 'nullable|numeric|min:0',
        ], $chequeShape);
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

    public static function methodLabel(string $method): string
    {
        return DocumentPayment::labelFor($method);
    }

    public static function statusLabel(?string $status): string
    {
        if ($status === self::STATUS_UNPAID) {
            return 'پرداخت‌نشده';
        }
        if ($status === self::STATUS_PARTIAL) {
            return 'بخشی پرداخت‌شده';
        }

        return 'پرداخت‌شده';
    }

    public static function mixedLabelFromMethods(array $methods): string
    {
        $unique = [];
        foreach ($methods as $method) {
            $normalized = self::normalizeMethod((string) $method);
            if ($normalized && ! in_array($normalized, $unique, true)) {
                $unique[] = $normalized;
            }
        }
        if ($unique === []) {
            return 'نقد';
        }
        if (count($unique) === 1) {
            return self::methodLabel($unique[0]);
        }

        return 'ترکیبی ('.implode(' + ', array_map([self::class, 'methodLabel'], $unique)).')';
    }

    public static function normalizeMethod($raw): ?string
    {
        $value = trim(mb_strtolower((string) $raw));
        if ($value === '') {
            return null;
        }
        if (in_array($value, ['account', 'cash', 'نقد', 'نقدی', 'از حساب'], true)) {
            return self::METHOD_ACCOUNT;
        }
        if (in_array($value, ['cheque', 'check', 'چک', 'چکی'], true)) {
            return self::METHOD_CHEQUE;
        }
        if (in_array($value, ['credit', 'نسیه', 'نسيه'], true)) {
            return self::METHOD_CREDIT;
        }
        if (in_array($value, ['mixed', 'ترکیبی', 'تركيبي'], true)) {
            return self::METHOD_MIXED;
        }

        return null;
    }

    /**
     * فیلدهای پرداخت برای ثبت هزینه/فاکتور.
     *
     * @return array<string, mixed>
     */
    public static function resolveOnCreate(int $atelierId, array $fields, float $amount, string $table = 'expenses'): array
    {
        $amount = round($amount, 2);

        if (! Schema::hasColumn($table, 'payment_status')) {
            $shopAccountId = $fields['shop_account_id'] ?? null;
            if ($shopAccountId) {
                self::assertCanDebit($atelierId, (int) $shopAccountId, $amount);
            }

            return [
                'shop_account_id' => $shopAccountId ? (int) $shopAccountId : null,
            ];
        }

        $splits = self::splitsFromFields($fields, $amount);
        self::assertAccountSplits($atelierId, $splits);

        return self::summaryFromSplits($splits);
    }

    /**
     * @param  array<int, array<string, mixed>>  $splits
     * @return array<string, mixed>
     */
    public static function summaryFromSplits(array $splits): array
    {
        $methods = [];
        $accountId = null;
        $unsettled = 0.0;
        foreach ($splits as $split) {
            $methods[] = $split['method'];
            if ($split['method'] === self::METHOD_ACCOUNT && ! $accountId && ! empty($split['shop_account_id'])) {
                $accountId = (int) $split['shop_account_id'];
            }
            if (empty($split['settled'])) {
                $unsettled += (float) $split['amount'];
            }
        }
        $unique = array_values(array_unique($methods));
        $total = 0.0;
        foreach ($splits as $split) {
            $total += (float) $split['amount'];
        }
        $unsettled = round($unsettled, 2);
        $total = round($total, 2);

        if ($unsettled <= 0.001) {
            $status = self::STATUS_PAID;
        } elseif (abs($unsettled - $total) <= 0.001) {
            $status = self::STATUS_UNPAID;
        } else {
            $status = self::STATUS_PARTIAL;
        }

        return [
            'payment_method' => count($unique) === 1 ? $unique[0] : self::METHOD_MIXED,
            'payment_status' => $status,
            'paid_at' => $status === self::STATUS_PAID ? now() : null,
            'shop_account_id' => $accountId,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function splitsFromFields(array $fields, float $amount, ?Model $model = null): array
    {
        $amount = round($amount, 2);
        $rows = [];

        if (! empty($fields['payments']) && is_array($fields['payments'])) {
            foreach (array_values($fields['payments']) as $index => $row) {
                if (! is_array($row)) {
                    throw new RuntimeException('فرمت پرداخت‌ها نامعتبر است.');
                }
                $rows[] = self::normalizeSplitRow($row, $index, $fields);
            }
        } elseif (self::hasConvenienceAmounts($fields)) {
            $cash = round((float) ($fields['cash_amount'] ?? 0), 2);
            $cheque = round((float) ($fields['cheque_amount'] ?? 0), 2);
            $credit = round((float) ($fields['credit_amount'] ?? 0), 2);
            if ($cash > 0) {
                $rows[] = self::normalizeSplitRow([
                    'method' => self::METHOD_ACCOUNT,
                    'amount' => $cash,
                    'shop_account_id' => $fields['shop_account_id'] ?? null,
                ], 0, $fields);
            }
            if ($cheque > 0) {
                $rows[] = self::normalizeSplitRow([
                    'method' => self::METHOD_CHEQUE,
                    'amount' => $cheque,
                    'cheque_id' => $fields['cheque_id'] ?? null,
                    'cheque' => $fields['cheque'] ?? null,
                    'shop_account_id' => $fields['shop_account_id'] ?? null,
                ], count($rows), $fields);
            }
            if ($credit > 0) {
                $rows[] = self::normalizeSplitRow([
                    'method' => self::METHOD_CREDIT,
                    'amount' => $credit,
                ], count($rows), $fields);
            }
        } else {
            $method = self::normalizeMethod($fields['payment_method'] ?? null);
            $shopAccountId = $fields['shop_account_id'] ?? ($model->shop_account_id ?? null);
            if ($method === null || $method === self::METHOD_MIXED) {
                $method = $shopAccountId ? self::METHOD_ACCOUNT : self::METHOD_CREDIT;
            }
            $rows[] = self::normalizeSplitRow([
                'method' => $method,
                'amount' => $amount,
                'shop_account_id' => $shopAccountId,
                'cheque_id' => $fields['cheque_id'] ?? null,
                'cheque' => $fields['cheque'] ?? null,
            ], 0, $fields);
        }

        if ($rows === []) {
            throw new RuntimeException('حداقل یک روش پرداخت با مبلغ مشخص لازم است.');
        }

        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float) $row['amount'];
        }
        if (abs(round($sum, 2) - $amount) > 0.01) {
            throw new RuntimeException(
                'جمع مبالغ پرداخت ('.number_format(round($sum, 2)).') باید برابر مبلغ سند ('.number_format($amount).') باشد.'
            );
        }

        return $rows;
    }

    public static function hasConvenienceAmounts(array $fields): bool
    {
        foreach (['cash_amount', 'cheque_amount', 'credit_amount'] as $key) {
            if (isset($fields[$key]) && $fields[$key] !== '' && $fields[$key] !== null) {
                return true;
            }
        }

        return false;
    }

    public static function requestHasPaymentSplits($request): bool
    {
        if (! $request) {
            return false;
        }

        return $request->exists('payments')
            || $request->exists('cash_amount')
            || $request->exists('cheque_amount')
            || $request->exists('credit_amount');
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    protected static function normalizeSplitRow(array $row, int $index, array $fallback): array
    {
        $method = self::normalizeMethod($row['method'] ?? null);
        if (! $method || $method === self::METHOD_MIXED) {
            throw new RuntimeException('روش پرداخت باید نقد، چک یا نسیه باشد.');
        }
        $amount = round((float) ($row['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('مبلغ هر پرداخت باید بیشتر از صفر باشد.');
        }

        $shopAccountId = $row['shop_account_id'] ?? null;
        $chequeId = $row['cheque_id'] ?? null;
        $cheque = $row['cheque'] ?? null;
        $settled = false;

        if ($method === self::METHOD_ACCOUNT) {
            if (! $shopAccountId) {
                $shopAccountId = $fallback['shop_account_id'] ?? null;
            }
            if (! $shopAccountId) {
                throw new RuntimeException('برای پرداخت نقد باید حساب را انتخاب کنید.');
            }
            $settled = true;
        }

        if ($method === self::METHOD_CHEQUE) {
            if (! $chequeId && (! is_array($cheque) || $cheque === [])) {
                $chequeId = $fallback['cheque_id'] ?? null;
                $cheque = $cheque ?: ($fallback['cheque'] ?? null);
            }
            if (! $chequeId && (! is_array($cheque) || $cheque === [])) {
                throw new RuntimeException('برای پرداخت چکی مشخصات چک یا شناسه چک لازم است.');
            }
            if (! $shopAccountId) {
                $shopAccountId = $fallback['shop_account_id'] ?? null;
            }
        }

        return [
            'method' => $method,
            'amount' => $amount,
            'shop_account_id' => $shopAccountId ? (int) $shopAccountId : null,
            'cheque_id' => $chequeId ? (int) $chequeId : null,
            'cheque' => is_array($cheque) ? $cheque : null,
            'settled' => $settled,
            'sort_order' => $index,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $splits
     */
    public static function assertAccountSplits(int $atelierId, array $splits, ?Model $ignore = null): void
    {
        $byAccount = [];
        foreach ($splits as $split) {
            if ($split['method'] !== self::METHOD_ACCOUNT || empty($split['shop_account_id'])) {
                continue;
            }
            $id = (int) $split['shop_account_id'];
            $byAccount[$id] = ($byAccount[$id] ?? 0) + (float) $split['amount'];
        }
        foreach ($byAccount as $accountId => $sum) {
            self::assertCanDebit($atelierId, $accountId, round($sum, 2), $ignore);
        }
    }

    public static function unsetPaymentRequestFields(array &$fields): void
    {
        unset(
            $fields['cheque'],
            $fields['cheque_id'],
            $fields['payment_method'],
            $fields['payments'],
            $fields['cash_amount'],
            $fields['cheque_amount'],
            $fields['credit_amount']
        );
    }

    public static function settle(Model $model, int $shopAccountId, ?float $amount = null, bool $postAccounting = true): Model
    {
        if (! $model instanceof Expense && ! $model instanceof Invoice) {
            throw new RuntimeException('این سند قابل تسویه از حساب نیست.');
        }

        $atelierId = (int) $model->atelier_id;
        $toPost = [];

        if (self::supportsSplits() && $model->payments()->exists()) {
            $creditQuery = $model->payments()->where('method', self::METHOD_CREDIT)->where('settled', false);
            $remaining = round((float) (clone $creditQuery)->sum('amount'), 2);
            if ($remaining <= 0.001) {
                throw new RuntimeException('بدهی نسیه برای این سند باقی نمانده است.');
            }
            $pay = $amount === null ? $remaining : round($amount, 2);
            if ($pay <= 0 || $pay - $remaining > 0.001) {
                throw new RuntimeException('مبلغ تسویه نامعتبر است. باقی‌مانده نسیه: '.number_format($remaining));
            }
            self::assertCanDebit($atelierId, $shopAccountId, $pay, $model);

            $left = $pay;
            foreach ($creditQuery->orderBy('sort_order')->orderBy('id')->get() as $row) {
                if ($left <= 0.001) {
                    break;
                }
                $rowAmount = round((float) $row->amount, 2);
                if ($rowAmount - $left <= 0.001) {
                    $row->update([
                        'settled' => true,
                        'shop_account_id' => $shopAccountId,
                    ]);
                    $toPost[] = (int) $row->id;
                    $left = round($left - $rowAmount, 2);
                } else {
                    $row->update(['amount' => round($rowAmount - $left, 2)]);
                    $created = DocumentPayment::create([
                        'atelier_id' => $atelierId,
                        'invoice_id' => $model instanceof Invoice ? $model->id : null,
                        'expense_id' => $model instanceof Expense ? $model->id : null,
                        'method' => self::METHOD_ACCOUNT,
                        'amount' => $left,
                        'shop_account_id' => $shopAccountId,
                        'settled' => true,
                        'sort_order' => (int) $model->payments()->max('sort_order') + 1,
                    ]);
                    $toPost[] = (int) $created->id;
                    $left = 0;
                }
            }
            self::refreshDocumentSummary($model);
            $fresh = $model->fresh();
            if ($postAccounting) {
                self::postSettledPayments($toPost, $fresh);
            }

            return $fresh;
        }

        if (self::supports($model) && self::isPaid($model) && $model->shop_account_id) {
            throw new RuntimeException('این سند قبلاً از حساب پرداخت شده است.');
        }

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
        if (self::supportsSplits()) {
            self::ensureLegacySplit($model);
            $legacy = $model->payments()->orderByDesc('id')->first();
            if ($legacy) {
                $toPost[] = (int) $legacy->id;
            }
        }
        $fresh = $model->fresh();
        if ($postAccounting) {
            self::postSettledPayments($toPost, $fresh);
        }

        return $fresh;
    }

    /**
     * @param  array<int, int>  $paymentIds
     */
    protected static function postSettledPayments(array $paymentIds, Model $document): void
    {
        foreach (array_unique($paymentIds) as $id) {
            if ($id <= 0) {
                continue;
            }
            $payment = DocumentPayment::query()->find($id);
            if ($payment) {
                AccountingDocumentPoster::postPaymentSettle($payment, $document);
            }
        }
    }

    /**
     * بعد از وصول چک: فقط سهم چک از حساب کسر می‌شود.
     */
    public static function trySettleAfterClear(Model $model, ?int $shopAccountId, ?int $chequeId = null): bool
    {
        if (! $shopAccountId) {
            return false;
        }

        if (self::supportsSplits() && $model->payments()->exists()) {
            $row = $model->payments()->where('method', self::METHOD_CHEQUE)->where('settled', false)
                ->when($chequeId, function ($q) use ($chequeId) {
                    $q->where('cheque_id', $chequeId);
                })
                ->orderBy('sort_order')
                ->first();
            if (! $row) {
                if (self::isPaid($model) && $model->shop_account_id) {
                    return true;
                }

                return false;
            }
            try {
                self::assertCanDebit((int) $model->atelier_id, $shopAccountId, (float) $row->amount, $model);
                $row->update([
                    'settled' => true,
                    'shop_account_id' => $shopAccountId,
                ]);
                self::refreshDocumentSummary($model);

                return true;
            } catch (RuntimeException $e) {
                return false;
            }
        }

        if (self::supports($model) && self::isPaid($model) && $model->shop_account_id) {
            return true;
        }

        try {
            self::settle($model, $shopAccountId, null, false);

            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    public static function unpayCheque(Model $model, int $chequeId): void
    {
        if (self::supportsSplits() && $model->payments()->where('cheque_id', $chequeId)->exists()) {
            $model->payments()->where('cheque_id', $chequeId)->update(['settled' => false]);
            self::refreshDocumentSummary($model);

            return;
        }

        self::unpay($model);
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

    /**
     * @return array<string, mixed>
     */
    public static function breakdown(Model $model): array
    {
        $empty = [
            'cash_amount' => 0.0,
            'cheque_amount' => 0.0,
            'credit_amount' => 0.0,
            'remaining_credit' => 0.0,
            'methods' => [],
            'method_labels' => [],
            'payments' => [],
        ];
        if (! self::supportsSplits()) {
            $method = self::normalizeMethod($model->payment_method) ?: self::METHOD_ACCOUNT;
            $amount = round((float) $model->amount, 2);
            $empty['methods'] = [$method];
            $empty['method_labels'] = [self::methodLabel($method)];
            if ($method === self::METHOD_ACCOUNT) {
                $empty['cash_amount'] = $amount;
            } elseif ($method === self::METHOD_CHEQUE) {
                $empty['cheque_amount'] = $amount;
            } else {
                $empty['credit_amount'] = $amount;
                $empty['remaining_credit'] = ($model->payment_status ?? '') === self::STATUS_UNPAID ? $amount : 0.0;
            }

            return $empty;
        }

        $model->loadMissing('payments.cheque', 'payments.shopAccount');
        $cash = $cheque = $credit = $remaining = 0.0;
        $methods = [];
        foreach ($model->payments as $row) {
            $methods[] = $row->method;
            if ($row->method === self::METHOD_ACCOUNT) {
                $cash += (float) $row->amount;
            } elseif ($row->method === self::METHOD_CHEQUE) {
                $cheque += (float) $row->amount;
            } else {
                $credit += (float) $row->amount;
                if (! $row->settled) {
                    $remaining += (float) $row->amount;
                }
            }
        }

        return [
            'cash_amount' => round($cash, 2),
            'cheque_amount' => round($cheque, 2),
            'credit_amount' => round($credit, 2),
            'remaining_credit' => round($remaining, 2),
            'methods' => array_values(array_unique($methods)),
            'method_labels' => array_map([self::class, 'methodLabel'], array_values(array_unique($methods))),
            'payments' => $model->payments,
        ];
    }

    public static function refreshDocumentSummary(Model $model): void
    {
        if (! self::supportsSplits()) {
            return;
        }
        $model->unsetRelation('payments');
        $splits = $model->payments()->orderBy('sort_order')->orderBy('id')->get()->map(function (DocumentPayment $row) {
            return [
                'method' => $row->method,
                'amount' => (float) $row->amount,
                'shop_account_id' => $row->shop_account_id,
                'settled' => (bool) $row->settled,
            ];
        })->all();
        if ($splits === []) {
            return;
        }
        $summary = self::summaryFromSplits($splits);
        $firstCheque = $model->payments()->whereNotNull('cheque_id')->orderBy('sort_order')->value('cheque_id');
        if (Schema::hasColumn($model->getTable(), 'cheque_id')) {
            $summary['cheque_id'] = $firstCheque;
        }
        $model->update($summary);
    }

    public static function settledAmountOnAccount(Model $model, int $accountId): float
    {
        if (self::supportsSplits() && $model->payments()->exists()) {
            return round((float) $model->payments()
                ->where('settled', true)
                ->where('shop_account_id', $accountId)
                ->sum('amount'), 2);
        }

        $isPaid = ! Schema::hasColumn($model->getTable(), 'payment_status')
            || ($model->payment_status ?? self::STATUS_PAID) === self::STATUS_PAID;
        if ($isPaid && (int) $model->shop_account_id === $accountId) {
            return round((float) $model->amount, 2);
        }

        return 0.0;
    }

    public static function remainingCredit(Model $model): float
    {
        if (self::supportsSplits() && $model->payments()->exists()) {
            return round((float) $model->payments()
                ->where('method', self::METHOD_CREDIT)
                ->where('settled', false)
                ->sum('amount'), 2);
        }
        if (($model->payment_method ?? '') === self::METHOD_CREDIT && ($model->payment_status ?? '') === self::STATUS_UNPAID) {
            return round((float) $model->amount, 2);
        }

        return 0.0;
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

    public static function attachIssuedCheque(Model $model, array $chequeFields, string $userName, ?float $amount = null): Cheque
    {
        $cheque = Cheque::create([
            'atelier_id' => (int) $model->atelier_id,
            'type' => Cheque::TYPE_ISSUED,
            'cheque_number' => $chequeFields['cheque_number'],
            'bank_name' => $chequeFields['bank_name'] ?? null,
            'payee' => $chequeFields['payee'] ?? null,
            'amount' => $amount !== null ? $amount : $model->amount,
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

        if (Schema::hasColumn($model->getTable(), 'cheque_id') && ! $model->cheque_id) {
            $model->update(['cheque_id' => $cheque->id]);
        }

        return $cheque;
    }

    public static function linkExistingCheque(Model $model, int $chequeId, ?float $amount = null): Cheque
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
        $linkedToThis = ($model instanceof Expense && (int) $cheque->expense_id === (int) $model->id)
            || ($model instanceof Invoice && (int) $cheque->invoice_id === (int) $model->id);
        if (! $linkedToThis && ($cheque->expense_id || $cheque->invoice_id || $cheque->purchase_id)) {
            throw new RuntimeException('این چک قبلاً به سند دیگری وصل شده است.');
        }

        $payload = [
            'shop_account_id' => $model->shop_account_id ?: $cheque->shop_account_id,
            'amount' => $amount !== null ? $amount : $model->amount,
        ];
        if ($model instanceof Expense) {
            $payload['expense_id'] = $model->id;
            $payload['expense_type'] = $model->type ?: 'جاری';
        } else {
            $payload['invoice_id'] = $model->id;
        }
        $cheque->update($payload);

        if (Schema::hasColumn($model->getTable(), 'cheque_id') && ! $model->cheque_id) {
            $model->update(['cheque_id' => $cheque->id]);
        }

        return $cheque->fresh();
    }

    public static function attachChequeFromRequest(Model $model, array $fields, string $userName): ?Cheque
    {
        if (self::supportsSplits()) {
            return self::persistSplits($model, $fields, $userName);
        }

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

    public static function persistSplits(Model $model, array $fields, string $userName): ?Cheque
    {
        $splits = self::splitsFromFields($fields, (float) $model->amount, $model);
        $firstCheque = null;

        foreach ($splits as $index => $split) {
            $chequeId = $split['cheque_id'] ?? null;
            if ($split['method'] === self::METHOD_CHEQUE) {
                if (! empty($split['cheque_id'])) {
                    $cheque = self::linkExistingCheque($model, (int) $split['cheque_id'], (float) $split['amount']);
                } else {
                    $chequePayload = $split['cheque'] ?? [];
                    $chequePayload['due_date'] = self::parseJalaliDate($chequePayload['due_date'] ?? null);
                    $chequePayload['issue_date'] = self::parseJalaliDate($chequePayload['issue_date'] ?? null);
                    if (empty($chequePayload['due_date'])) {
                        throw new RuntimeException('تاریخ سررسید چک الزامی است.');
                    }
                    $cheque = self::attachIssuedCheque($model, $chequePayload, $userName, (float) $split['amount']);
                    if (! empty($split['shop_account_id']) && ! $cheque->shop_account_id) {
                        $cheque->update(['shop_account_id' => $split['shop_account_id']]);
                    }
                }
                $chequeId = $cheque->id;
                if (! $firstCheque) {
                    $firstCheque = $cheque;
                }
            }

            DocumentPayment::create([
                'atelier_id' => (int) $model->atelier_id,
                'invoice_id' => $model instanceof Invoice ? $model->id : null,
                'expense_id' => $model instanceof Expense ? $model->id : null,
                'method' => $split['method'],
                'amount' => $split['amount'],
                'shop_account_id' => $split['shop_account_id'],
                'cheque_id' => $chequeId,
                'settled' => (bool) $split['settled'],
                'sort_order' => $index,
            ]);
        }

        self::refreshDocumentSummary($model);

        return $firstCheque;
    }

    public static function replaceSplits(Model $model, array $fields, string $userName): void
    {
        if (! self::supportsSplits()) {
            return;
        }

        foreach ($model->payments as $row) {
            if ($row->cheque && $row->cheque->status === Cheque::STATUS_CLEARED) {
                throw new RuntimeException('این سند چک وصول‌شده دارد و روش پرداخت قابل تغییر نیست.');
            }
        }
        foreach ($model->payments as $row) {
            if ($row->cheque && $row->cheque->status === Cheque::STATUS_PENDING) {
                $row->cheque->update([
                    'status' => Cheque::STATUS_CANCELLED,
                    'invoice_id' => $model instanceof Invoice ? null : $row->cheque->invoice_id,
                    'expense_id' => $model instanceof Expense ? null : $row->cheque->expense_id,
                ]);
            }
        }
        $model->payments()->delete();
        if (Schema::hasColumn($model->getTable(), 'cheque_id')) {
            $model->update(['cheque_id' => null]);
        }
        $model->unsetRelation('payments');
        self::persistSplits($model, $fields, $userName);
    }

    public static function ensureLegacySplit(Model $model): void
    {
        if (! self::supportsSplits() || $model->payments()->exists()) {
            return;
        }
        $method = self::normalizeMethod($model->payment_method) ?: self::METHOD_ACCOUNT;
        if ($method === self::METHOD_MIXED) {
            $method = $model->shop_account_id ? self::METHOD_ACCOUNT : self::METHOD_CREDIT;
        }
        $settled = self::isPaid($model) || $method === self::METHOD_ACCOUNT;
        DocumentPayment::create([
            'atelier_id' => (int) $model->atelier_id,
            'invoice_id' => $model instanceof Invoice ? $model->id : null,
            'expense_id' => $model instanceof Expense ? $model->id : null,
            'method' => $method,
            'amount' => $model->amount,
            'shop_account_id' => $model->shop_account_id,
            'cheque_id' => $model->cheque_id ?? null,
            'settled' => $settled,
            'sort_order' => 0,
        ]);
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
