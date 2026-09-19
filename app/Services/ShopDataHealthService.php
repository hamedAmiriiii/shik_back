<?php

namespace App\Services;

use App\Models\Purchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * بررسی هوشمند دادهٔ فروشگاه: ورود اشتباه کاربر در برابر ناسازگاری محاسبات.
 */
class ShopDataHealthService
{
    public const GAP = 1000;

    public const SAMPLE_LIMIT = 8;

    /**
     * @return array{
     *   summary: array<string, int|float>,
     *   findings: array<int, array<string, mixed>>
     * }
     */
    public static function inspect(int $atelierId): array
    {
        $findings = [];
        if ($atelierId <= 0) {
            return self::payload($atelierId, $findings);
        }

        self::checkLineTotalMismatch($atelierId, $findings);
        self::checkSettlementGap($atelierId, $findings);
        self::checkReturnAmountGap($atelierId, $findings);
        self::checkInstallmentGap($atelierId, $findings);
        self::checkSalePriceZero($atelierId, $findings);
        self::checkPurchasePriceZeroOnSales($atelierId, $findings);
        self::checkSoldBelowCost($atelierId, $findings);
        self::checkSaleWithoutPhone($atelierId, $findings);
        self::checkNegativeStock($atelierId, $findings);
        self::checkProductZeroCost($atelierId, $findings);
        self::checkOpenDebts($atelierId, $findings);
        self::checkVoidedAsCreditReturn($atelierId, $findings);
        self::checkOrphanCreditFromReturn($atelierId, $findings);
        self::checkUnbalancedVouchers($atelierId, $findings);

        return self::payload($atelierId, $findings);
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @return array{summary: array<string, int|float>, findings: array<int, array<string, mixed>>}
     */
    protected static function payload(int $atelierId, array $findings): array
    {
        $error = 0;
        $warning = 0;
        $info = 0;
        foreach ($findings as $row) {
            $sev = (string) ($row['severity'] ?? 'info');
            if ($sev === 'error') {
                $error++;
            } elseif ($sev === 'warning') {
                $warning++;
            } else {
                $info++;
            }
        }

        $salesCount = (int) DB::table('purchases')->where('atelier_id', $atelierId)->count();

        return [
            'summary' => [
                'sales_count' => $salesCount,
                'finding_count' => count($findings),
                'error_count' => $error,
                'warning_count' => $warning,
                'info_count' => $info,
            ],
            'findings' => $findings,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<int, array<string, mixed>>  $samples
     */
    protected static function push(
        array &$findings,
        string $code,
        string $severity,
        string $category,
        string $title,
        string $detail,
        string $howToFix,
        int $count,
        array $samples,
        ?string $href = null,
        ?string $hrefLabel = null,
        ?float $amount = null
    ): void {
        if ($count <= 0) {
            return;
        }

        $findings[] = [
            'code' => $code,
            'severity' => $severity,
            'category' => $category,
            'title' => $title,
            'detail' => $detail,
            'how_to_fix' => $howToFix,
            'count' => $count,
            'amount' => $amount,
            'href' => $href,
            'href_label' => $hrefLabel,
            'samples' => $samples,
        ];
    }

    protected static function checkLineTotalMismatch(int $atelierId, array &$findings): void
    {
        $rows = DB::table('purchases as p')
            ->leftJoin('purchased_products as pp', 'pp.purchase_id', '=', 'p.id')
            ->where('p.atelier_id', $atelierId)
            ->where(function ($q) {
                $q->whereNull('p.payment_type')->orWhere('p.payment_type', '<>', 'installment');
            })
            ->where('p.total_amount', '>', 0)
            ->groupBy('p.id', 'p.payment_type', 'p.phone', 'p.total_amount', 'p.created_at')
            ->havingRaw('ABS(p.total_amount - IFNULL(SUM(pp.quantity * pp.sale_price), 0)) >= ?', [self::GAP])
            ->orderByRaw('ABS(p.total_amount - IFNULL(SUM(pp.quantity * pp.sale_price), 0)) DESC')
            ->limit(self::SAMPLE_LIMIT)
            ->selectRaw('p.id, p.phone, p.payment_type, p.total_amount, p.created_at, ROUND(IFNULL(SUM(pp.quantity * pp.sale_price), 0), 0) as lines_sum, ROUND(p.total_amount - IFNULL(SUM(pp.quantity * pp.sale_price), 0), 0) as gap')
            ->get();

        $count = $rows->count();
        if ($count >= self::SAMPLE_LIMIT) {
            $count = (int) DB::table('purchases as p')
                ->leftJoin('purchased_products as pp', 'pp.purchase_id', '=', 'p.id')
                ->where('p.atelier_id', $atelierId)
                ->where(function ($q) {
                    $q->whereNull('p.payment_type')->orWhere('p.payment_type', '<>', 'installment');
                })
                ->where('p.total_amount', '>', 0)
                ->groupBy('p.id', 'p.total_amount')
                ->havingRaw('ABS(p.total_amount - IFNULL(SUM(pp.quantity * pp.sale_price), 0)) >= ?', [self::GAP])
                ->get()
                ->count();
        }

        self::push(
            $findings,
            'line_total_mismatch',
            'error',
            'system',
            'جمع اقلام با مبلغ فاکتور نمی‌خواند',
            $count.' فاکتور هست که مبلغ ثبت‌شده با جمع کالاها فرق دارد.',
            'فاکتور را باز کنید و اقلام یا مبلغ را درست کنید. اگر خودتان ویرایش دستی کرده‌اید همان را برگردانید.',
            $count,
            self::mapPurchaseSamples($rows),
            '/admin/purchas',
            'لیست فروش‌ها'
        );
    }

    protected static function checkSettlementGap(int $atelierId, array &$findings): void
    {
        $hasCheque = Schema::hasTable('cheques');
        $q = DB::table('purchases as p')->where('p.atelier_id', $atelierId);
        if ($hasCheque) {
            $q->leftJoin('cheques as ch', 'ch.id', '=', 'p.cheque_id');
        }
        $chequeExpr = $hasCheque ? 'IFNULL(ch.amount, 0)' : '0';
        $debtGap = Schema::hasColumn('purchases', 'is_debt_settled')
            ? "IF(p.payment_type = 'debt' AND IFNULL(p.is_debt_settled, 0) = 0, p.total_amount - IFNULL(p.discount_amount, 0) - IFNULL(p.credit_used, 0) - IFNULL(p.card_amount, 0) - IFNULL(p.cash_amount, 0) - {$chequeExpr}, 0)"
            : '0';

        $gapSql = "p.total_amount - IFNULL(p.discount_amount, 0) - IFNULL(p.credit_used, 0) - IFNULL(p.card_amount, 0) - IFNULL(p.cash_amount, 0) - {$chequeExpr} - {$debtGap}";

        $rows = (clone $q)
            ->where(function ($w) {
                $w->whereNull('p.payment_type')->orWhereNotIn('p.payment_type', ['installment']);
            })
            ->where('p.total_amount', '>', 0)
            ->selectRaw("p.id, p.phone, p.payment_type, p.total_amount, p.created_at, ROUND({$gapSql}, 0) as gap")
            ->whereRaw("ABS({$gapSql}) >= ?", [self::GAP])
            ->orderByRaw("ABS({$gapSql}) DESC")
            ->limit(self::SAMPLE_LIMIT)
            ->get();

        self::push(
            $findings,
            'settlement_gap',
            'error',
            'system',
            'تسویه نقد/کارت با مبلغ فاکتور نمی‌خواند',
            $rows->count().' فاکتور تسویه ناقص یا اضافه دارد.',
            'فاکتور را باز کنید و مبلغ کارت، نقد، تخفیف و اعتبار را با مبلغ کالاها یکی کنید.',
            $rows->count(),
            self::mapPurchaseSamples($rows),
            '/admin/purchas',
            'لیست فروش‌ها'
        );
    }

    protected static function checkReturnAmountGap(int $atelierId, array &$findings): void
    {
        if (! Schema::hasTable('purchase_item_returns')) {
            return;
        }

        $rows = DB::table('purchase_item_returns')
            ->where('atelier_id', $atelierId)
            ->whereRaw('ABS(IFNULL(return_sale_total, 0) - (quantity * sale_price)) >= ?', [self::GAP])
            ->orderByRaw('ABS(IFNULL(return_sale_total, 0) - (quantity * sale_price)) DESC')
            ->limit(self::SAMPLE_LIMIT)
            ->get(['id', 'purchase_id', 'phone', 'return_sale_total', 'created_at']);

        self::push(
            $findings,
            'return_amount_mismatch',
            'error',
            'system',
            'مبلغ برگشت با تعداد × قیمت نمی‌خواند',
            $rows->count().' برگشت مبلغش با اقلام فرق دارد.',
            'از لیست برگشت، همان ردیف را بررسی کنید. اگر لازم شد با پشتیبانی مطرح کنید.',
            $rows->count(),
            $rows->map(fn ($r) => [
                'id' => (int) $r->purchase_id,
                'label' => 'برگشت #'.$r->id,
                'meta' => (string) ($r->phone ?: '—'),
                'amount' => round((float) $r->return_sale_total, 0),
                'at' => (string) $r->created_at,
            ])->all(),
            '/admin/returned-products',
            'برگشت‌ها'
        );
    }

    protected static function checkInstallmentGap(int $atelierId, array &$findings): void
    {
        if (! Schema::hasTable('installments')) {
            return;
        }

        $rows = DB::table('purchases as p')
            ->leftJoin('installments as i', 'i.purchase_id', '=', 'p.id')
            ->where('p.atelier_id', $atelierId)
            ->where('p.payment_type', 'installment')
            ->groupBy('p.id', 'p.phone', 'p.payment_type', 'p.total_amount', 'p.created_at')
            ->havingRaw('ABS(IFNULL(SUM(i.amount), 0) - p.total_amount) >= ? OR COUNT(i.id) = 0', [self::GAP])
            ->orderByDesc('p.id')
            ->limit(self::SAMPLE_LIMIT)
            ->selectRaw('p.id, p.phone, p.payment_type, p.total_amount, p.created_at, ROUND(IFNULL(SUM(i.amount), 0) - p.total_amount, 0) as gap')
            ->get();

        self::push(
            $findings,
            'installment_gap',
            'error',
            'system',
            'جمع اقساط با فاکتور نمی‌خواند',
            $rows->count().' فروش اقساطی قسط ناقص یا مبلغ نامیزان دارد.',
            'از صفحه اقساط همان فاکتور را باز کنید و قسط‌ها را با مبلغ فاکتور چک کنید.',
            $rows->count(),
            self::mapPurchaseSamples($rows),
            '/admin/installments',
            'اقساط'
        );
    }

    protected static function checkSalePriceZero(int $atelierId, array &$findings): void
    {
        $q = DB::table('purchased_products as pp')
            ->join('purchases as p', 'p.id', '=', 'pp.purchase_id')
            ->where('p.atelier_id', $atelierId)
            ->whereRaw('IFNULL(pp.sale_price, 0) = 0');
        $count = (clone $q)->count();
        $rows = $q->orderByDesc('p.id')->limit(self::SAMPLE_LIMIT)
            ->selectRaw('p.id, p.phone, p.payment_type, p.total_amount, p.created_at, COALESCE(pp.item_name, "") as item_name')
            ->get();

        self::push(
            $findings,
            'sale_price_zero',
            'error',
            'entry',
            'کالا با قیمت فروش صفر فروخته شده',
            $count.' قلم روی فاکتور قیمت فروش صفر دارد. فروش و سود این‌ها غلط می‌شود.',
            'در کالا قیمت فروش بگذارید و فاکتورهای قبلی را در صورت نیاز اصلاح یا برگشت کنید.',
            $count,
            self::mapPurchaseSamples($rows, 'item_name'),
            '/admin/product',
            'کالاها'
        );
    }

    protected static function checkPurchasePriceZeroOnSales(int $atelierId, array &$findings): void
    {
        $count = (int) DB::table('purchased_products as pp')
            ->join('purchases as p', 'p.id', '=', 'pp.purchase_id')
            ->where('p.atelier_id', $atelierId)
            ->whereRaw('IFNULL(pp.purchase_price, 0) = 0')
            ->count();
        if ($count <= 0) {
            return;
        }

        self::push(
            $findings,
            'purchase_price_zero',
            'warning',
            'entry',
            'بهای تمام‌شده روی فروش صفر است',
            $count.' قلم فروخته شده بدون قیمت خرید. سود ناخالص این‌ها بیش از واقعیت نشان داده می‌شود.',
            'روی کارت کالا قیمت خرید را وارد کنید تا فروش‌های بعدی درست حساب شوند.',
            $count,
            [],
            '/admin/product',
            'کالاها'
        );
    }

    protected static function checkSoldBelowCost(int $atelierId, array &$findings): void
    {
        $q = DB::table('purchased_products as pp')
            ->join('purchases as p', 'p.id', '=', 'pp.purchase_id')
            ->where('p.atelier_id', $atelierId)
            ->where('pp.sale_price', '>', 0)
            ->whereColumn('pp.purchase_price', '>', 'pp.sale_price');
        $count = (clone $q)->count();
        $rows = $q->orderByDesc('p.id')->limit(self::SAMPLE_LIMIT)
            ->selectRaw('p.id, p.phone, p.payment_type, ROUND(pp.sale_price, 0) as total_amount, p.created_at, COALESCE(pp.item_name, "") as item_name')
            ->get();

        self::push(
            $findings,
            'sold_below_cost',
            'warning',
            'entry',
            'فروش زیر بهای تمام‌شده',
            $count.' قلم ارزان‌تر از قیمت خرید فروخته شده.',
            'اگر تخفیف عمدی بوده ایراد نیست. اگر اشتباه است قیمت فروش کالا را درست کنید.',
            $count,
            self::mapPurchaseSamples($rows, 'item_name'),
            '/admin/purchas',
            'لیست فروش‌ها'
        );
    }

    protected static function checkSaleWithoutPhone(int $atelierId, array &$findings): void
    {
        $q = DB::table('purchases')
            ->where('atelier_id', $atelierId)
            ->where('total_amount', '>', 0)
            ->where(function ($w) {
                $w->whereNull('phone')->orWhere('phone', '');
            });
        $count = (clone $q)->count();
        $rows = $q->orderByDesc('id')->limit(self::SAMPLE_LIMIT)
            ->get(['id', 'phone', 'payment_type', 'total_amount', 'created_at']);

        self::push(
            $findings,
            'sale_without_phone',
            'info',
            'entry',
            'فروش بدون شماره موبایل',
            $count.' فاکتور بدون مشتری ثبت شده. باشگاه و نسیه برای این‌ها ساخته نمی‌شود.',
            'برای مشتری ثابت موبایل بگیرید. فروش متفرقه نقدی می‌تواند بدون موبایل بماند.',
            $count,
            self::mapPurchaseSamples($rows),
            '/admin/purchas',
            'لیست فروش‌ها'
        );
    }

    protected static function checkNegativeStock(int $atelierId, array &$findings): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        $q = DB::table('products')->where('atelier_id', $atelierId)->where('quantity', '<', 0);
        if (Schema::hasColumn('products', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        $count = (clone $q)->count();
        $rows = $q->orderBy('quantity')->limit(self::SAMPLE_LIMIT)->get(['id', 'name', 'quantity']);

        self::push(
            $findings,
            'negative_stock',
            'warning',
            'entry',
            'موجودی کالا منفی است',
            $count.' کالا موجودی زیر صفر دارد. یا فروش بیش از موجودی زده‌اید یا موجودی اول دوره وارد نشده.',
            'از صفحه کالا موجودی را اصلاح کنید یا خرید کالا را ثبت کنید.',
            $count,
            $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'label' => (string) $r->name,
                'meta' => 'موجودی '.$r->quantity,
                'amount' => null,
                'at' => null,
            ])->all(),
            '/admin/product',
            'کالاها'
        );
    }

    protected static function checkProductZeroCost(int $atelierId, array &$findings): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        $q = DB::table('products')
            ->where('atelier_id', $atelierId)
            ->whereRaw('IFNULL(purchase_price, 0) = 0')
            ->where('quantity', '>', 0);
        if (Schema::hasColumn('products', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        $count = (int) $q->count();

        self::push(
            $findings,
            'product_zero_cost',
            'warning',
            'entry',
            'کالای موجود بدون قیمت خرید',
            $count.' کالا در انبار است ولی قیمت خرید ندارد. سود گزارش مالی برای این‌ها غلط می‌شود.',
            'روی هر کالا قیمت خرید را وارد کنید.',
            $count,
            [],
            '/admin/product',
            'کالاها'
        );
    }

    protected static function checkOpenDebts(int $atelierId, array &$findings): void
    {
        if (! Schema::hasColumn('purchases', 'is_debt_settled')) {
            return;
        }

        $q = Purchase::query()
            ->forAtelier($atelierId)
            ->where('payment_type', 'debt')
            ->where('is_debt_settled', false)
            ->where('total_amount', '>', 0);
        $count = (clone $q)->count();
        $amount = round((float) (clone $q)->sum('total_amount'), 0);
        $rows = $q->orderByDesc('id')->limit(self::SAMPLE_LIMIT)
            ->get(['id', 'phone', 'payment_type', 'total_amount', 'created_at']);

        self::push(
            $findings,
            'open_debts',
            'warning',
            'operation',
            'نسیه تسویه نشده',
            $count.' فاکتور نسیه به مبلغ '.number_format($amount).' تومان هنوز در سیستم تسویه نشده. اگر پول را گرفته‌اید و اینجا نزده‌اید، موجودی حساب و بدهکاران غلط است.',
            'از بدهکاران همان فاکتور را تسویه نقد یا کارت کنید. اگر هنوز نگرفته‌اید، پیگیری وصول است.',
            $count,
            self::mapPurchaseSamples($rows),
            '/admin/purchase-debts',
            'بدهکاران',
            $amount
        );
    }

    protected static function checkVoidedAsCreditReturn(int $atelierId, array &$findings): void
    {
        if (! Schema::hasTable('purchase_item_returns')) {
            return;
        }

        $rows = DB::table('purchases as p')
            ->join('purchase_item_returns as r', 'r.purchase_id', '=', 'p.id')
            ->where('p.atelier_id', $atelierId)
            ->where('p.total_amount', 0)
            ->whereRaw('IFNULL(r.credit_used_refund, 0) >= 1000');
        if (Schema::hasColumn('purchase_item_returns', 'cash_refunded')) {
            $rows->whereRaw('IFNULL(r.cash_refunded, 0) < 1');
        }
        $rows = $rows
            ->where(function ($w) {
                $w->whereNull('p.payment_type')->orWhere('p.payment_type', 'cash');
            })
            ->orderByDesc('r.id')
            ->limit(self::SAMPLE_LIMIT)
            ->selectRaw('p.id, p.phone, p.payment_type, r.return_sale_total as total_amount, r.created_at, ROUND(IFNULL(r.credit_used_refund, 0), 0) as gap')
            ->get();

        self::push(
            $findings,
            'voided_as_credit_return',
            'error',
            'entry',
            'فروش نقدی کامل برگشت خورده و به اعتبار رفته',
            $rows->count().' فاکتور الان مبلغ صفر دارد ولی برگشت به کیف پول مشتری زده شده. اگر فروش اشتباه بوده و پولی رد و بدل نشده، باید فاکتور را حذف می‌کردید نه برگشت به اعتبار.',
            'اگر پولی جابه‌جا نشده، اعتبار همان مشتری را صفر کنید و با پشتیبانی برای اصلاح دفتر هماهنگ شوید. برای دفعات بعد از حذف فاکتور استفاده کنید.',
            $rows->count(),
            self::mapPurchaseSamples($rows),
            '/admin/customers',
            'مشتریان'
        );
    }

    protected static function checkOrphanCreditFromReturn(int $atelierId, array &$findings): void
    {
        if (! Schema::hasTable('user_shiksho') || ! Schema::hasTable('purchase_item_returns')) {
            return;
        }

        $rows = DB::table('user_shiksho as u')
            ->where('u.atelier_id', $atelierId)
            ->where('u.credit', '>=', self::GAP)
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('purchase_item_returns as r')
                    ->whereColumn('r.phone', 'u.phone')
                    ->whereColumn('r.atelier_id', 'u.atelier_id')
                    ->whereRaw('IFNULL(r.credit_used_refund, 0) >= 1000');
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('purchases as p')
                    ->whereColumn('p.phone', 'u.phone')
                    ->whereColumn('p.atelier_id', 'u.atelier_id')
                    ->where('p.total_amount', '>', 0);
            })
            ->limit(self::SAMPLE_LIMIT)
            ->get(['u.phone', 'u.name', 'u.credit']);

        self::push(
            $findings,
            'orphan_credit',
            'warning',
            'operation',
            'اعتبار مشتری از برگشت مانده، بدون فاکتور باز',
            $rows->count().' مشتری اعتبار دارد ولی فروش باز ندارد. معمولاً بعد از برگشت کامل فروش نقدی پیش می‌آید.',
            'اگر برگشت واقعی بوده، اعتبار درست است. اگر فروش اشتباه بوده و پولی رد نشده، اعتبار را دستی صفر کنید.',
            $rows->count(),
            $rows->map(fn ($r) => [
                'id' => 0,
                'label' => (string) ($r->name ?: $r->phone),
                'meta' => (string) $r->phone,
                'amount' => round((float) $r->credit, 0),
                'at' => null,
            ])->all(),
            '/admin/customers',
            'مشتریان'
        );
    }

    protected static function checkUnbalancedVouchers(int $atelierId, array &$findings): void
    {
        if (! Schema::hasTable('accounting_vouchers') || ! Schema::hasTable('accounting_lines')) {
            return;
        }

        $rows = DB::table('accounting_vouchers as v')
            ->join('accounting_lines as l', 'l.voucher_id', '=', 'v.id')
            ->where('v.atelier_id', $atelierId)
            ->groupBy('v.id', 'v.number', 'v.source_type', 'v.description')
            ->havingRaw('ABS(SUM(l.debit) - SUM(l.credit)) >= 1')
            ->orderBy('v.number')
            ->limit(self::SAMPLE_LIMIT)
            ->selectRaw('v.id, v.number, v.source_type, LEFT(v.description, 80) as description, ROUND(SUM(l.debit) - SUM(l.credit), 0) as gap')
            ->get();

        self::push(
            $findings,
            'unbalanced_voucher',
            'error',
            'system',
            'سند حسابداری نامتوازن',
            $rows->count().' سند بدهکار و بستانکارش یکی نیست.',
            'از اسناد حسابداری همان شماره را باز کنید. اگر سند دستی است اصلاح کنید؛ وگرنه به پشتیبانی بگویید.',
            $rows->count(),
            $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'label' => 'سند '.$r->number,
                'meta' => (string) ($r->description ?: $r->source_type),
                'amount' => round((float) $r->gap, 0),
                'at' => null,
            ])->all(),
            '/admin/accounting/vouchers',
            'اسناد حسابداری'
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected static function mapPurchaseSamples($rows, ?string $labelField = null): array
    {
        return $rows->map(function ($r) use ($labelField) {
            $label = $labelField && isset($r->{$labelField}) && $r->{$labelField} !== ''
                ? (string) $r->{$labelField}
                : 'فاکتور #'.$r->id;

            return [
                'id' => (int) $r->id,
                'label' => $label,
                'meta' => trim((string) (($r->phone ?? '') ?: '—').' '.($r->payment_type ?? '')),
                'amount' => isset($r->total_amount) ? round((float) $r->total_amount, 0) : null,
                'at' => isset($r->created_at) ? (string) $r->created_at : null,
            ];
        })->all();
    }
}
