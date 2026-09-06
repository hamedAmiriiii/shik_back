<?php

namespace App\Console\Commands;

use App\Models\AccountingVoucher;
use App\Models\Cheque;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Installment;
use App\Models\Invoice;
use App\Models\ProducedGood;
use App\Models\ProducedGoodIngredient;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchasedProduct;
use App\Models\RawMaterial;
use App\Models\RawMaterialLot;
use App\Models\Setting;
use App\Models\ShopAccount;
use App\Models\ShopEmployee;
use App\Models\UserShiksho;
use App\Services\AccountingDocumentPoster;
use App\Services\AccountingLedger;
use App\Services\AccountingMiscPoster;
use App\Services\AccountingReportService;
use App\Services\AccountingSalePoster;
use App\Services\ChartOfAccountsSeeder;
use App\Services\DocumentPaymentService;
use App\Services\PurchaseItemReturnService;
use App\Services\RawMaterialFifoService;
use App\Services\ShopAccountBalanceService;
use App\Services\ShopPosSaleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AccountingFlowSelfTest extends Command
{
    public const PHONE = '09000001313';

    public const TAG = '[FLOW-TEST]';

    protected $signature = 'accounting:flow-self-test {atelier_id=13} {--cleanup-only : فقط پاک کردن دادهٔ تست قبلی}';

    protected $description = 'تست زندهٔ فروش/برگشت/فاکتور/هزینه/حقوق/مواد با همان پوسترهای برنامه';

    protected int $atelierId = 13;

    /** @var array<int, array{name: string, ok: bool, detail: string}> */
    protected array $checks = [];

    public function handle(): int
    {
        $result = $this->runFor((int) $this->argument('atelier_id'), (bool) $this->option('cleanup-only'));
        if (! empty($result['error'])) {
            $this->error($result['error']);

            return 1;
        }
        if (! empty($result['cleaned'])) {
            $this->info('دادهٔ تست قبلی پاک شد.');

            return 0;
        }
        $this->table(['سناریو', 'وضعیت', 'شرح'], array_map(function (array $row) {
            return [$row['name'], $row['ok'] ? 'قبول' : 'رد', $row['detail']];
        }, $result['checks'] ?? []));
        $pnl = $result['profit_loss'] ?? [];
        $this->info('سود دفتر فروشگاه (کل): خالص '.number_format((float) ($pnl['net_profit'] ?? 0)));

        return ! empty($result['ok']) ? 0 : 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function runFor(int $atelierId, bool $cleanupOnly = false): array
    {
        $this->atelierId = $atelierId;
        $this->checks = [];
        if ($this->atelierId <= 0) {
            return ['ok' => false, 'error' => 'atelier_id نامعتبر است.'];
        }
        if (! AccountingLedger::ready()) {
            return ['ok' => false, 'error' => 'جداول دفتر آماده نیست.'];
        }

        Setting::setShopContext($this->atelierId);
        ChartOfAccountsSeeder::ensureForAtelier($this->atelierId);

        $this->cleanupPrevious();
        if ($cleanupOnly) {
            return ['ok' => true, 'cleaned' => true, 'atelier_id' => $this->atelierId];
        }

        $this->runStep('فروش نقد + سند purchase', function () {
            $sale = $this->makeSale('cash', 100000, 40000, ['cash' => 100000], 'cash');
            $this->assertVoucher(AccountingVoucher::SOURCE_PURCHASE, (int) $sale->id);
            $this->assertPnlDelta(['sales' => 100000, 'cogs' => 40000, 'net' => 60000]);
        });

        $this->runStep('فروش نسیه + تسویه بدون درآمد تکراری', function () {
            $sale = $this->makeSale('debt', 80000, 30000, [], 'debt');
            $revBefore = $this->codeNet('411', true);
            $sale->update([
                'is_debt_settled' => true,
                'debt_settled_at' => now(),
                'debt_settled_card_amount' => 0,
                'debt_settled_cash_amount' => 80000,
            ]);
            AccountingSalePoster::postDebtSettle($sale->fresh());
            $this->assertVoucher(AccountingVoucher::SOURCE_DEBT_SETTLE, (int) $sale->id);
            if (abs($this->codeNet('411', true) - $revBefore) > 0.02) {
                throw new RuntimeException('تسویه نسیه درآمد را تکرار کرد');
            }
        });

        $this->runStep('فروش چک + وصول بدون درآمد تکراری', function () {
            $cheque = Cheque::create([
                'atelier_id' => $this->atelierId,
                'type' => Cheque::TYPE_RECEIVED,
                'status' => Cheque::STATUS_PENDING,
                'cheque_number' => 'FT-R-'.time(),
                'amount' => 40000,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'title' => self::TAG.' چک فروش',
                'user_name' => 'تست',
            ]);
            $sale = $this->makeSale('cheque', 50000, 20000, ['cash' => 10000], 'cheque', $cheque->id);
            $revBefore = $this->codeNet('411', true);
            $cheque->refresh()->clear();
            $this->assertVoucher(AccountingVoucher::SOURCE_CHEQUE_CLEAR, (int) $cheque->id);
            if (abs($this->codeNet('411', true) - $revBefore) > 0.02) {
                throw new RuntimeException('وصول چک فروش درآمد را تکرار کرد');
            }
        });

        $this->runStep('فروش اقساط + وصول یک قسط', function () {
            $customer = $this->customer();
            $customer->installment_credit = max((float) $customer->installment_credit, 1000000);
            $customer->save();
            $sale = $this->makeSale('installment', 90000, 45000, ['cash' => 30000], 'installment');
            Installment::create([
                'purchase_id' => $sale->id,
                'installment_number' => 2,
                'amount' => 30000,
                'due_date' => now()->addMonth()->toDateString(),
                'is_paid' => false,
            ]);
            $inst = Installment::query()->where('purchase_id', $sale->id)->where('is_paid', false)->first();
            if (! $inst) {
                throw new RuntimeException('قسط ساخته نشد');
            }
            $revBefore = $this->codeNet('411', true);
            $inst->update(['is_paid' => true, 'paid_at' => now()]);
            AccountingSalePoster::postInstallmentPay($inst->fresh());
            $this->assertVoucher(AccountingVoucher::SOURCE_INSTALLMENT_PAY, (int) $inst->id);
            if (abs($this->codeNet('411', true) - $revBefore) > 0.02) {
                throw new RuntimeException('قسط درآمد را تکرار کرد');
            }
        });

        $this->runStep('برگشت فروش نقد', function () {
            $sale = Purchase::query()
                ->where('atelier_id', $this->atelierId)
                ->where('client_id', 'like', 'flow-test-cash-%')
                ->orderByDesc('id')
                ->first();
            if (! $sale) {
                throw new RuntimeException('فروش نقد برای برگشت پیدا نشد');
            }
            $line = $sale->purchasedProducts()->first();
            PurchaseItemReturnService::processReturn($sale->fresh(['purchasedProducts']), $line, 1, 'تست');
            $logId = (int) DB::table('purchase_item_returns')->where('purchase_id', $sale->id)->max('id');
            $this->assertVoucher(AccountingVoucher::SOURCE_PURCHASE_RETURN, $logId);
        });

        $this->runStep('هزینه جاری + برگشت (حذف)', function () {
            $expense = $this->makeExpense('جاری', 5000, 'هزینه جاری');
            $this->assertVoucher(AccountingVoucher::SOURCE_EXPENSE, (int) $expense->id);
            $opexBefore = $this->codeNet('611', false);
            AccountingDocumentPoster::reverseExpense($expense);
            $expense->delete();
            if (abs($this->codeNet('611', false) - ($opexBefore - 5000)) > 0.02) {
                throw new RuntimeException('برگشت هزینه جاری ماندهٔ ۶۱۱ را صفر نکرد');
            }
        });

        $this->runStep('هزینه سرمایه سود را کم نکند', function () {
            $netBefore = (float) AccountingReportService::profitLoss($this->atelierId)['net_profit'];
            $expense = $this->makeExpense('سرمایه', 20000, 'هزینه سرمایه');
            $this->assertVoucher(AccountingVoucher::SOURCE_EXPENSE, (int) $expense->id);
            $netAfter = (float) AccountingReportService::profitLoss($this->atelierId)['net_profit'];
            if (abs($netAfter - $netBefore) > 0.02) {
                throw new RuntimeException('هزینه سرمایه سود را تغییر داد');
            }
        });

        $this->runStep('حقوق و مساعده روی ۶۱۲', function () {
            $payBefore = $this->codeNet('612', false);
            $salary = $this->makeExpense('جاری', 12000, 'پرداخت حقوق', 'salary');
            $advance = $this->makeExpense('جاری', 3000, 'مساعده', 'advance');
            $this->assertVoucher(AccountingVoucher::SOURCE_EXPENSE, (int) $salary->id);
            $this->assertVoucher(AccountingVoucher::SOURCE_EXPENSE, (int) $advance->id);
            if (abs($this->codeNet('612', false) - ($payBefore + 15000)) > 0.02) {
                throw new RuntimeException('حقوق/مساعده به ۶۱۲ نرفت');
            }
        });

        $this->runStep('فاکتور خرید نسیه + تسویه', function () {
            $invoice = $this->makeInvoice(15000, 'فاکتور خرید نسیه', 'credit');
            $this->assertVoucher(AccountingVoucher::SOURCE_INVOICE, (int) $invoice->id);
            $revBefore = $this->codeNet('411', true);
            $account = $this->shopAccount();
            if ($account) {
                $available = ShopAccountBalanceService::availableBalance($account);
                if ($available + 0.001 >= 15000) {
                    DocumentPaymentService::settle($invoice->fresh(), (int) $account->id);
                    $this->assertHasPaymentVoucher((int) $invoice->id);
                }
            }
            if (abs($this->codeNet('411', true) - $revBefore) > 0.02) {
                throw new RuntimeException('تسویه خرید درآمد ساخت');
            }
        });

        $this->runStep('فاکتور خرید چک + وصول', function () {
            $invoice = $this->makeInvoice(8000, 'فاکتور خرید چک', 'cheque');
            $this->assertVoucher(AccountingVoucher::SOURCE_INVOICE, (int) $invoice->id);
            $cheque = Cheque::query()->where('invoice_id', $invoice->id)->first()
                ?: Cheque::query()->where('title', 'like', self::TAG.'%')->where('type', Cheque::TYPE_ISSUED)->orderByDesc('id')->first();
            if (! $cheque) {
                throw new RuntimeException('چک فاکتور ساخته نشد');
            }
            $netBefore = (float) AccountingReportService::profitLoss($this->atelierId)['net_profit'];
            $cheque->fresh()->clear();
            $netAfter = (float) AccountingReportService::profitLoss($this->atelierId)['net_profit'];
            if (abs($netAfter - $netBefore) > 0.02) {
                throw new RuntimeException('وصول چک خرید سود را عوض کرد');
            }
        });

        $this->runStep('خرید مواد + تولید + فروش ساخته', function () {
            if (! Schema::hasTable('raw_materials') || ! Schema::hasTable('produced_goods')) {
                throw new RuntimeException('جداول تولید موجود نیست');
            }
            $material = RawMaterial::create([
                'atelier_id' => $this->atelierId,
                'name' => self::TAG.' ماده',
                'sale_price' => 40000,
            ]);
            $lot = RawMaterialLot::create([
                'atelier_id' => $this->atelierId,
                'raw_material_id' => $material->id,
                'quantity_kg' => 2,
                'remaining_kg' => 2,
                'price_per_kg' => 25000,
                'purchased_at' => now(),
                'note' => self::TAG,
            ]);
            $invoice = $this->makeInvoice(50000, 'خرید مواد خام', 'credit');
            $lot->update(['invoice_id' => $invoice->id]);
            AccountingDocumentPoster::syncInvoice($invoice->fresh(['payments', 'rawMaterialLots']));
            $good = ProducedGood::create([
                'atelier_id' => $this->atelierId,
                'name' => self::TAG.' ساخته',
                'sale_price' => 40000,
            ]);
            ProducedGoodIngredient::create([
                'produced_good_id' => $good->id,
                'raw_material_id' => $material->id,
                'grams_per_kg' => 1000,
            ]);
            $production = app(RawMaterialFifoService::class)->produce($good->fresh(['ingredients.rawMaterial']), 1, self::TAG);
            $this->assertVoucher(AccountingVoucher::SOURCE_PRODUCTION, (int) $production->id);
            $sale = $this->makeFinishedSale($good->fresh(), 1, 40000, (float) $production->cost_per_kg);
            $this->assertVoucher(AccountingVoucher::SOURCE_PURCHASE, (int) $sale->id);
        });

        $this->runStep('تراز آزمایشی و ترازنامه بعد از همه', function () {
            $tb = AccountingReportService::trialBalance($this->atelierId);
            if (! ($tb['balanced'] ?? false)) {
                throw new RuntimeException('تراز آزمایشی نامتوازن است');
            }
            $bs = AccountingReportService::balanceSheet($this->atelierId);
            if (! ($bs['equation']['balanced'] ?? false)) {
                throw new RuntimeException('معادلهٔ ترازنامه برقرار نیست');
            }
        });

        $pnl = AccountingReportService::profitLoss($this->atelierId);
        $failed = array_values(array_filter($this->checks, fn (array $c) => ! $c['ok']));

        return [
            'ok' => $failed === [],
            'suite' => 'flow-v2',
            'atelier_id' => $this->atelierId,
            'accepted' => count($this->checks) - count($failed),
            'total' => count($this->checks),
            'checks' => $this->checks,
            'profit_loss' => $pnl,
        ];
    }

    protected function runStep(string $name, callable $fn): void
    {
        $before = AccountingReportService::profitLoss($this->atelierId);
        $this->lastPnl = $before;
        try {
            $fn();
            $this->checks[] = ['name' => $name, 'ok' => true, 'detail' => 'قبول'];
        } catch (\Throwable $e) {
            $this->checks[] = ['name' => $name, 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** @var array<string, mixed> */
    protected array $lastPnl = [];

    /**
     * @param  array<string, float>  $expect
     */
    protected function assertPnlDelta(array $expect): void
    {
        $now = AccountingReportService::profitLoss($this->atelierId);
        foreach ($expect as $key => $delta) {
            $map = [
                'sales' => 'sales',
                'cogs' => 'cogs',
                'net' => 'net_profit',
                'opex' => 'operating_expense',
                'payroll' => 'payroll',
            ];
            $field = $map[$key] ?? $key;
            $got = round((float) $now[$field] - (float) ($this->lastPnl[$field] ?? 0), 2);
            if (abs($got - $delta) > 0.02) {
                throw new RuntimeException("{$key} دلتا={$got} انتظار={$delta}");
            }
        }
        $this->lastPnl = $now;
    }

    protected function assertVoucher(string $sourceType, int $sourceId): void
    {
        $v = AccountingVoucher::query()
            ->where('atelier_id', $this->atelierId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', AccountingVoucher::STATUS_POSTED)
            ->whereNull('reverses_voucher_id')
            ->first();
        if (! $v) {
            throw new RuntimeException("سند {$sourceType}:{$sourceId} ثبت نشد");
        }
        $dr = (float) $v->lines()->sum('debit');
        $cr = (float) $v->lines()->sum('credit');
        if (abs($dr - $cr) > 0.02) {
            throw new RuntimeException("سند {$sourceType}:{$sourceId} نامتوازن dr={$dr} cr={$cr}");
        }
    }

    protected function assertHasPaymentVoucher(int $invoiceId): void
    {
        $ids = DB::table('document_payments')->where('invoice_id', $invoiceId)->pluck('id');
        foreach ($ids as $id) {
            $found = AccountingVoucher::query()
                ->where('atelier_id', $this->atelierId)
                ->where('source_type', AccountingVoucher::SOURCE_DOCUMENT_PAYMENT)
                ->where('source_id', $id)
                ->where('status', AccountingVoucher::STATUS_POSTED)
                ->whereNull('reverses_voucher_id')
                ->exists();
            if ($found) {
                return;
            }
        }
        throw new RuntimeException('سند تسویه فاکتور ثبت نشد');
    }

    protected function codeNet(string $code, bool $creditNature): float
    {
        $row = DB::table('accounting_lines as l')
            ->join('accounting_vouchers as v', 'v.id', '=', 'l.voucher_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('v.atelier_id', $this->atelierId)
            ->whereIn('v.status', ['posted', 'reversed'])
            ->where('a.code', $code)
            ->selectRaw('SUM(l.debit) as d, SUM(l.credit) as c')
            ->first();
        $d = (float) ($row->d ?? 0);
        $c = (float) ($row->c ?? 0);

        return $creditNature ? round($c - $d, 2) : round($d - $c, 2);
    }

    /**
     * @param  array{cash?: float, card?: float}  $settlement
     */
    protected function makeSale(
        string $suffix,
        float $salePrice,
        float $cost,
        array $settlement,
        string $paymentType,
        ?int $chequeId = null
    ): Purchase {
        $product = $this->product();
        $pos = app(ShopPosSaleService::class);
        $prepared = $pos->prepareLines([[
            'product_id' => $product->id,
            'quantity' => 1,
        ]], $this->atelierId);
        $pos->assertStock($prepared);

        $purchase = Purchase::create([
            'phone' => self::PHONE,
            'total_amount' => $salePrice,
            'discount_amount' => 0,
            'credit_used' => 0,
            'credit_earned' => 0,
            'payment_type' => $paymentType,
            'cheque_id' => $chequeId,
            'card_amount' => $settlement['card'] ?? 0,
            'cash_amount' => $settlement['cash'] ?? 0,
            'is_debt_settled' => false,
            'atelier_id' => $this->atelierId,
            'client_id' => 'flow-test-'.$suffix.'-'.uniqid(),
        ]);
        if ($chequeId) {
            Cheque::query()->where('id', $chequeId)->update(['purchase_id' => $purchase->id]);
            $linked = Cheque::query()->find($chequeId);
            if ($linked) {
                AccountingMiscPoster::reverseReceivedCheque($linked);
            }
        }
        $line = PurchasedProduct::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'item_name' => $product->name,
            'quantity' => 1,
            'purchase_price' => $cost,
            'sale_price' => $salePrice,
        ]);
        $pos->commitStock($prepared, [$line]);
        $purchase->load(['purchasedProducts', 'cheque']);
        AccountingSalePoster::post($purchase);

        return $purchase->fresh(['purchasedProducts', 'cheque']);
    }

    protected function makeFinishedSale(ProducedGood $good, float $qty, float $salePrice, float $cost): Purchase
    {
        $pos = app(ShopPosSaleService::class);
        $prepared = $pos->prepareLines([[
            'produced_good_id' => $good->id,
            'item_type' => ShopPosSaleService::KIND_PRODUCED_GOOD,
            'quantity' => $qty,
        ]], $this->atelierId);
        $pos->assertStock($prepared);
        $purchase = Purchase::create([
            'phone' => self::PHONE,
            'total_amount' => $salePrice,
            'discount_amount' => 0,
            'credit_used' => 0,
            'credit_earned' => 0,
            'payment_type' => 'cash',
            'card_amount' => 0,
            'cash_amount' => $salePrice,
            'atelier_id' => $this->atelierId,
            'client_id' => 'flow-test-finished-'.uniqid(),
        ]);
        $line = PurchasedProduct::create([
            'purchase_id' => $purchase->id,
            'produced_good_id' => $good->id,
            'item_name' => $good->name,
            'quantity' => $qty,
            'purchase_price' => $cost,
            'sale_price' => $salePrice,
        ]);
        $pos->commitStock($prepared, [$line]);
        $purchase->load(['purchasedProducts']);
        AccountingSalePoster::post($purchase);

        return $purchase->fresh(['purchasedProducts']);
    }

    protected function makeExpense(string $type, float $amount, string $title, ?string $payrollType = null): Expense
    {
        $fields = [
            'payment_method' => DocumentPaymentService::METHOD_CREDIT,
            'title' => self::TAG.' '.$title,
        ];
        $payment = DocumentPaymentService::resolveOnCreate($this->atelierId, $fields, $amount, 'expenses');
        $expense = Expense::create(array_merge([
            'user_name' => 'تست',
            'date' => now()->toDateString(),
            'amount' => $amount,
            'title' => self::TAG.' '.$title,
            'type' => $type,
            'atelier_id' => $this->atelierId,
        ], $payment));

        AccountingDocumentPoster::syncExpense($expense->fresh(['payments']), $payrollType);

        return $expense->fresh();
    }

    protected function makeInvoice(float $amount, string $title, string $method): Invoice
    {
        $fields = [
            'payment_method' => $method,
            'title' => self::TAG.' '.$title,
        ];
        if ($method === DocumentPaymentService::METHOD_CHEQUE) {
            $jalali = \Morilog\Jalali\Jalalian::fromCarbon(now());
            $fields['cheque'] = [
                'cheque_number' => 'FT-I-'.uniqid(),
                'bank_name' => 'تست',
                'title' => self::TAG.' چک خرید',
                'due_date' => [
                    'year' => $jalali->getYear(),
                    'month' => $jalali->getMonth(),
                    'day' => $jalali->getDay(),
                ],
            ];
        }
        $payment = DocumentPaymentService::resolveOnCreate($this->atelierId, $fields, $amount, 'invoices');
        $invoice = Invoice::create(array_merge([
            'amount' => $amount,
            'title' => self::TAG.' '.$title,
            'date' => now()->toDateString(),
            'user_name' => 'تست',
            'atelier_id' => $this->atelierId,
        ], $payment));
        DocumentPaymentService::attachChequeFromRequest($invoice, $fields, 'تست');
        AccountingDocumentPoster::syncInvoice($invoice->fresh(['payments', 'rawMaterialLots']));

        return $invoice->fresh(['payments']);
    }

    protected function product(): Product
    {
        $product = Product::query()
            ->where('atelier_id', $this->atelierId)
            ->where('name', self::TAG.' کالا')
            ->first();
        if (! $product) {
            $product = Product::create([
                'atelier_id' => $this->atelierId,
                'name' => self::TAG.' کالا',
                'barcode' => 'FLOWTEST-A'.$this->atelierId,
                'purchase_price' => 40000,
                'sale_price' => 100000,
                'quantity' => 50,
                'unit_type' => Product::UNIT_PIECE,
            ]);
        } else {
            $product->purchase_price = 40000;
            $product->sale_price = 100000;
            $product->quantity = max((float) $product->quantity, 20);
            $product->save();
        }

        return $product->fresh();
    }

    protected function customer(): UserShiksho
    {
        return UserShiksho::query()->firstOrCreate(
            ['phone' => self::PHONE, 'atelier_id' => $this->atelierId],
            ['name' => self::TAG.' مشتری', 'credit' => 0, 'installment_credit' => 1000000]
        );
    }

    protected function shopAccount(): ?ShopAccount
    {
        $q = ShopAccount::query()->where('atelier_id', $this->atelierId);
        if (ShopAccount::supportsTypes()) {
            $shop = (clone $q)->where('type', ShopAccount::TYPE_SHOP)->orderBy('sort_order')->first();
            if ($shop) {
                return $shop;
            }
        }

        return $q->orderBy('id')->first();
    }

    protected function cleanupPrevious(): void
    {
        $purchases = Purchase::query()
            ->where('atelier_id', $this->atelierId)
            ->where(function ($q) {
                $q->where('phone', self::PHONE)
                    ->orWhere('client_id', 'like', 'flow-test-%');
            })
            ->get();
        foreach ($purchases as $purchase) {
            AccountingSalePoster::reversePurchase($purchase);
            if (Schema::hasTable('purchase_item_returns')) {
                DB::table('purchase_item_returns')->where('purchase_id', $purchase->id)->delete();
            }
            $purchase->installments()->delete();
            $purchase->purchasedProducts()->delete();
            if ($purchase->cheque_id) {
                Cheque::query()->where('id', $purchase->cheque_id)->update(['purchase_id' => null]);
            }
            $purchase->delete();
        }

        $invoices = Invoice::query()
            ->where('atelier_id', $this->atelierId)
            ->where('title', 'like', self::TAG.'%')
            ->get();
        foreach ($invoices as $invoice) {
            AccountingDocumentPoster::reverseInvoice($invoice);
            if (Schema::hasTable('raw_material_lots') && Schema::hasColumn('raw_material_lots', 'invoice_id')) {
                RawMaterialLot::query()->where('invoice_id', $invoice->id)->update(['invoice_id' => null, 'invoice_item_id' => null]);
            }
            if (Schema::hasTable('invoice_items')) {
                DB::table('invoice_items')->where('invoice_id', $invoice->id)->delete();
            }
            if (Schema::hasTable('document_payments')) {
                DB::table('document_payments')->where('invoice_id', $invoice->id)->delete();
            }
            Cheque::query()->where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            $invoice->delete();
        }

        $expenses = Expense::query()
            ->where('atelier_id', $this->atelierId)
            ->where('title', 'like', self::TAG.'%')
            ->get();
        foreach ($expenses as $expense) {
            AccountingDocumentPoster::reverseExpense($expense);
            if (Schema::hasTable('document_payments')) {
                DB::table('document_payments')->where('expense_id', $expense->id)->delete();
            }
            $expense->delete();
        }

        if (class_exists(Income::class)) {
            Income::query()
                ->where('atelier_id', $this->atelierId)
                ->where('title', 'like', '%'.self::TAG.'%')
                ->delete();
        }

        Cheque::query()
            ->where('atelier_id', $this->atelierId)
            ->where('title', 'like', self::TAG.'%')
            ->delete();

        if (Schema::hasTable('productions')) {
            $goodIds = ProducedGood::query()
                ->where('atelier_id', $this->atelierId)
                ->where('name', 'like', self::TAG.'%')
                ->pluck('id');
            foreach ($goodIds as $goodId) {
                $productions = \App\Models\Production::query()->where('produced_good_id', $goodId)->get();
                foreach ($productions as $production) {
                    try {
                        app(RawMaterialFifoService::class)->reverseProduction($production);
                    } catch (\Throwable $e) {
                        $production->consumptions()->delete();
                        $production->delete();
                    }
                }
            }
            ProducedGoodIngredient::query()->whereIn('produced_good_id', $goodIds)->delete();
            ProducedGood::query()->whereIn('id', $goodIds)->delete();
        }
        if (Schema::hasTable('raw_materials')) {
            $matIds = RawMaterial::query()
                ->where('atelier_id', $this->atelierId)
                ->where('name', 'like', self::TAG.'%')
                ->pluck('id');
            RawMaterialLot::query()->whereIn('raw_material_id', $matIds)->delete();
            RawMaterial::query()->whereIn('id', $matIds)->delete();
        }

        ShopEmployee::query()
            ->where('atelier_id', $this->atelierId)
            ->where('name', self::TAG.' کارمند')
            ->delete();
    }
}
