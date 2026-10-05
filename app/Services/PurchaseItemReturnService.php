<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\DailyShopReconciliation;
use App\Models\Purchase;
use App\Models\PurchaseItemReturn;
use App\Models\PurchasedProduct;
use App\Models\Product;
use App\Models\ReturnedProduct;
use App\Models\Setting;
use App\Models\UserShiksho;
use App\Services\CustomerCreditExpenseService;
use App\Tools\PhoneTools;
use App\Tools\PriceTools;
use App\Tools\ProductQuantityTools;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class PurchaseItemReturnService
{
    /** مبلغ کارتخوان به اعتبار مشتری (پیش‌فرض) */
    public const CARD_REFUND_CUSTOMER_CREDIT = 'customer_credit';

    /** مبلغ کارتخوان از حساب فروشگاه برداشت و به مشتری داده شود */
    public const CARD_REFUND_SHOP_ACCOUNT = 'shop_account';

    /**
     * فاکتورِ روزِ تطبیق‌نخورده: مبلغ از کارتخوانِ همان روز (هنوز واریز نشده) برمی‌گردد
     * و از جمع کارتخوان آن روز کم می‌شود.
     */
    public const CARD_REFUND_POS_TERMINAL = 'pos_terminal';

    /** @return array<int, string> */
    public static function cardRefundDestinations(): array
    {
        return [
            self::CARD_REFUND_CUSTOMER_CREDIT,
            self::CARD_REFUND_SHOP_ACCOUNT,
            self::CARD_REFUND_POS_TERMINAL,
        ];
    }

    /**
     * امکان برگشت از کارتخوانِ روز فاکتور: تطبیق آن روز هنوز ثبت نشده باشد.
     *
     * @return array{available: bool, reason: string|null, date: string|null, date_jalali: string|null, pending_card_amount: float}
     */
    public static function posTerminalRefundAvailability(Purchase $purchase, ?int $atelierId = null): array
    {
        $result = [
            'available' => false,
            'reason' => null,
            'date' => null,
            'date_jalali' => null,
            'pending_card_amount' => 0.0,
        ];

        $atelierId = $atelierId ?? self::purchaseAtelierId($purchase);
        $rawCreatedAt = $purchase->getRawOriginal('created_at');
        if (! $atelierId || ! $rawCreatedAt) {
            $result['reason'] = 'فروشگاه یا تاریخ این فاکتور مشخص نیست.';

            return $result;
        }

        $day = Carbon::parse($rawCreatedAt, 'Asia/Tehran')->startOfDay();
        $dayKey = $day->format('Y-m-d');
        $result['date'] = $dayKey;
        $result['date_jalali'] = Jalalian::fromCarbon($day)->format('Y/m/d');

        if (Schema::hasTable('daily_shop_reconciliations')
            && DailyShopReconciliation::query()
                ->where('atelier_id', $atelierId)
                ->whereDate('date', $dayKey)
                ->exists()
        ) {
            $result['reason'] = 'تطبیق روز '.$result['date_jalali'].' ثبت شده و مبلغ کارتخوانش واریز حساب شده است.';

            return $result;
        }

        $metrics = ShopSalesReportService::salesAndProfitForDate($atelierId, $day);
        $result['pending_card_amount'] = round(max(0, (float) $metrics['card_amount']), 2);
        $result['available'] = true;

        return $result;
    }

    public static function purchaseAtelierId(Purchase $purchase): ?int
    {
        if ((int) $purchase->atelier_id > 0) {
            return (int) $purchase->atelier_id;
        }

        $purchase->loadMissing(['purchasedProducts.product', 'purchasedProducts.producedGood', 'purchasedProducts.rawMaterial']);
        foreach ($purchase->purchasedProducts as $line) {
            $id = (int) (optional($line->product)->atelier_id
                ?? optional($line->producedGood)->atelier_id
                ?? optional($line->rawMaterial)->atelier_id);
            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }

    /**
     * مهلت بازگشت از تنظیم فروشگاه. خالی = بدون محدودیت.
     * مثلاً ۱ روز: همان روز فروش و روز بعد مجاز است؛ از روز دوم خطا می‌دهد.
     */
    public static function assertSaleReturnWindow(Purchase $purchase): void
    {
        $atelierId = self::purchaseAtelierId($purchase);
        if ($atelierId === null || $atelierId <= 0) {
            return;
        }
        if (! Schema::hasTable('settings')) {
            return;
        }

        $raw = Setting::query()
            ->where('atelier_id', $atelierId)
            ->where('key', 'sale_return_days')
            ->value('value');
        if ($raw === null || trim((string) $raw) === '' || ! is_numeric($raw)) {
            return;
        }

        $days = (int) $raw;
        if ($days < 0) {
            return;
        }

        $soldAt = $purchase->created_at
            ? Carbon::parse($purchase->created_at)->timezone('Asia/Tehran')->startOfDay()
            : now('Asia/Tehran')->startOfDay();
        $today = now('Asia/Tehran')->startOfDay();
        if ($today->lt($soldAt)) {
            return;
        }

        $elapsed = (int) $soldAt->diffInDays($today);
        if ($elapsed <= $days) {
            return;
        }

        if ($days === 0) {
            throw new \InvalidArgumentException('بازگشت این خرید فقط در همان روز فروش ممکن است.');
        }

        throw new \InvalidArgumentException(
            "مهلت بازگشت این خرید گذشته است. بازگشت فقط تا {$days} روز بعد از تاریخ فروش مجاز است."
        );
    }

    /**
     * مهلت بازگشت از تنظیم فروشگاه. خالی = بدون محدودیت.
     * مثلاً ۱ روز: همان روز فروش و روز بعد مجاز است؛ از روز دوم خطا می‌دهد.
     */
    public static function assertSaleReturnWindow(Purchase $purchase): void
    {
        $atelierId = self::purchaseAtelierId($purchase);
        if ($atelierId === null || $atelierId <= 0) {
            return;
        }
        if (! Schema::hasTable('settings')) {
            return;
        }

        $raw = Setting::query()
            ->where('atelier_id', $atelierId)
            ->where('key', 'sale_return_days')
            ->value('value');
        if ($raw === null || trim((string) $raw) === '' || ! is_numeric($raw)) {
            return;
        }

        $days = (int) $raw;
        if ($days < 0) {
            return;
        }

        $soldAt = $purchase->created_at
            ? Carbon::parse($purchase->created_at)->timezone('Asia/Tehran')->startOfDay()
            : now('Asia/Tehran')->startOfDay();
        $today = now('Asia/Tehran')->startOfDay();
        if ($today->lt($soldAt)) {
            return;
        }

        $elapsed = (int) $soldAt->diffInDays($today);
        if ($elapsed <= $days) {
            return;
        }

        if ($days === 0) {
            throw new \InvalidArgumentException('بازگشت این خرید فقط در همان روز فروش ممکن است.');
        }

        throw new \InvalidArgumentException(
            "مهلت بازگشت این خرید گذشته است. بازگشت فقط تا {$days} روز بعد از تاریخ فروش مجاز است."
        );
    }

    /**
     * برگشت کامل همه اقلام باقی‌مانده فاکتور.
     *
     * @param  array{card_refund_destination?: string, shop_account_id?: int|null}  $refundOptions
     * @return array<string, mixed>
     */
    public static function processFullReturn(
        Purchase $purchase,
        ?string $phone = null,
        ?string $userName = null,
        ?string $notes = null,
        array $refundOptions = []
    ): array {
        return DB::transaction(function () use ($purchase, $phone, $userName, $notes, $refundOptions) {
            $purchase->load('purchasedProducts');
            if ($purchase->purchasedProducts->isEmpty()) {
                throw new \InvalidArgumentException('این خرید اقلام باقی‌مانده برای برگشت ندارد.');
            }

            $items = $purchase->purchasedProducts->all();
            $returnedItems = [];
            $rows = [];
            $creditRefunded = 0.0;
            $creditEarnedReversed = 0.0;
            $cashRefunded = 0.0;
            $cardRefunded = 0.0;

            foreach ($items as $item) {
                $freshPurchase = Purchase::query()->with('purchasedProducts')->find($purchase->id);
                $freshItem = PurchasedProduct::query()->find($item->id);
                if (! $freshPurchase || ! $freshItem) {
                    continue;
                }
                $qty = (float) $freshItem->quantity;
                if ($qty <= 0) {
                    continue;
                }
                $result = self::processReturn(
                    $freshPurchase,
                    $freshItem,
                    $qty,
                    $userName,
                    $notes,
                    $phone,
                    false,
                    $refundOptions
                );
                $returnedItems[] = $result['returned_item'];
                $rows[] = $result['row'];
                $creditRefunded += (float) $result['returned_item']['credit_refunded'];
                $creditEarnedReversed += (float) $result['returned_item']['credit_earned_reversed'];
                $cashRefunded += (float) ($result['returned_item']['cash_refunded'] ?? 0);
                $cardRefunded += (float) ($result['returned_item']['card_refunded'] ?? 0);
            }

            $purchase = Purchase::query()
                ->with(['purchasedProducts.product', 'purchasedProducts.producedGood', 'purchasedProducts.rawMaterial'])
                ->findOrFail($purchase->id);

            $customer = self::findUserShiksho($purchase);
            $options = self::normalizeRefundOptions($refundOptions);

            return [
                'full_return' => true,
                'returned_items' => $returnedItems,
                'rows' => $rows,
                'credit_refunded' => round($creditRefunded, 2),
                'credit_earned_reversed' => round($creditEarnedReversed, 2),
                'cash_refunded' => round($cashRefunded, 2),
                'card_refunded' => round($cardRefunded, 2),
                'card_refund_destination' => $options['card_refund_destination'],
                'shop_account_id' => $options['shop_account_id'],
                'customer_credit' => $customer ? (float) $customer->credit : 0,
                'phone' => $purchase->phone,
                'purchase' => $purchase,
            ];
        });
    }

    /**
     * برگشت یک یا چند عدد از خط فاکتور.
     * سهم پرداخت‌نشده (نسیه/قسط/چک) بسته می‌شود؛ نقد از صندوق؛ کارت به اعتبار (پیش‌فرض) یا حساب فروشگاه.
     *
     * @param  array{card_refund_destination?: string, shop_account_id?: int|null}  $refundOptions
     * @return array<string, mixed>
     */
    public static function processReturn(
        Purchase $purchase,
        PurchasedProduct $purchasedProduct,
        float $returnQuantity,
        ?string $userName = null,
        ?string $notes = null,
        ?string $phone = null,
        bool $useTransaction = true,
        array $refundOptions = []
    ): array {
        $run = function () use ($purchase, $purchasedProduct, $returnQuantity, $userName, $notes, $phone, $refundOptions) {
            $options = self::normalizeRefundOptions($refundOptions);
            if ((int) $purchasedProduct->purchase_id !== (int) $purchase->id) {
                throw new \InvalidArgumentException('این محصول متعلق به این خرید نیست');
            }
            self::assertSaleReturnWindow($purchase);

            $purchasedProduct->load(['product', 'producedGood', 'rawMaterial']);

            $product = $purchasedProduct->product;
            $unitType = Product::UNIT_KG;
            if ($product instanceof Product) {
                $unitType = $product->unit_type ?? Product::UNIT_PIECE;
            } elseif (! $purchasedProduct->produced_good_id && ! $purchasedProduct->raw_material_id) {
                throw new \InvalidArgumentException('محصول یافت نشد');
            }
            $returnQuantity = ProductQuantityTools::normalize($returnQuantity, $unitType);
            $lineQty = ProductQuantityTools::normalize($purchasedProduct->quantity, $unitType);

            if ($error = ProductQuantityTools::validateReturnQuantity($returnQuantity, $lineQty, $unitType)) {
                throw new \InvalidArgumentException($error);
            }

            $unitSale = (float) $purchasedProduct->sale_price;
            $unitPurchase = (float) $purchasedProduct->purchase_price;
            $returnAmount = round($unitSale * $returnQuantity, 2);
            $returnPurchaseTotal = round($unitPurchase * $returnQuantity, 2);

            $purchase->load('purchasedProducts');
            $lineTotalBeforeReturn = (float) $purchase->purchasedProducts->sum(function ($pp) {
                return (float) $pp->sale_price * (float) $pp->quantity;
            });
            $ratio = $lineTotalBeforeReturn > 0
                ? min(1, $returnAmount / $lineTotalBeforeReturn)
                : 1;

            $atelierId = (int) ($purchase->atelier_id
                ?? optional($product)->atelier_id
                ?? optional($purchasedProduct->producedGood)->atelier_id
                ?? optional($purchasedProduct->rawMaterial)->atelier_id);
            if ($atelierId <= 0) {
                throw new \InvalidArgumentException('فروشگاه این فاکتور مشخص نیست');
            }

            if ($options['shop_account_id']) {
                $account = \App\Models\ShopAccount::query()->find($options['shop_account_id']);
                if (! $account || (int) $account->atelier_id !== $atelierId) {
                    throw new \InvalidArgumentException('حساب انتخاب‌شده متعلق به این فروشگاه نیست.');
                }
                if ($account->isTill()) {
                    throw new \InvalidArgumentException('برای برگشت کارتخوان، حساب بانکی یا تنخواه انتخاب کنید (نه صندوق نقد).');
                }
            }

            $purchase->loadMissing(['installments', 'cheque']);
            $settlement = self::allocateReturnSettlement($purchase, $ratio, $options);
            $origEarned = (float) $purchase->credit_earned;
            $creditEarnedReversed = PriceTools::roundToman($origEarned * $ratio);
            if ($creditEarnedReversed > $origEarned) {
                $creditEarnedReversed = $origEarned;
            }

            $cashRefunded = (float) $settlement['cash'];
            $cardRefunded = (float) $settlement['card'];
            $loyaltyRefunded = (float) $settlement['loyalty'];
            $cardToCredit = (float) $settlement['card_credit'];
            $creditRefunded = PriceTools::roundToman($loyaltyRefunded + $cardToCredit);

            if ($cardRefunded >= 0.01
                && $options['card_refund_destination'] === self::CARD_REFUND_SHOP_ACCOUNT
                && ! $options['shop_account_id']
            ) {
                throw new \InvalidArgumentException(
                    'برای برگشت مبلغ کارتخوان از حساب، یک حساب فروشگاه انتخاب کنید.'
                );
            }

            $cardFromPos = (float) ($settlement['pos'] ?? 0);
            if ($cardFromPos >= 0.01) {
                $posAvailability = self::posTerminalRefundAvailability($purchase, $atelierId);
                if (! $posAvailability['available']) {
                    throw new \InvalidArgumentException((string) $posAvailability['reason']);
                }
                if ($posAvailability['pending_card_amount'] + 0.001 < $cardFromPos) {
                    throw new \InvalidArgumentException(
                        'مبلغ کارتخوان روز '.$posAvailability['date_jalali'].' کافی نیست. مبلغ کارتخوان آن روز: '
                        .number_format($posAvailability['pending_card_amount'], 0).' تومان'
                    );
                }
            }

            $cardFromAccount = (float) ($settlement['card_account'] ?? 0);
            if ($cardFromAccount >= 0.01 && $options['shop_account_id']) {
                $account = \App\Models\ShopAccount::query()->find($options['shop_account_id']);
                if ($account) {
                    $available = ShopAccountBalanceService::availableBalance($account);
                    if ($available + 0.001 < $cardFromAccount) {
                        throw new \InvalidArgumentException(
                            'موجودی حساب کافی نیست. موجودی: '.number_format($available, 0).' تومان'
                        );
                    }
                }
            }

            $needsCustomer = $creditRefunded >= 0.01 || $creditEarnedReversed >= 0.01
                || $purchase->phone || $phone;
            $customer = $needsCustomer
                ? self::resolveCustomer($purchase, $phone, $atelierId)
                : null;

            if ($customer) {
                $newCredit = max(0, PriceTools::roundToman(
                    (float) $customer->credit + $creditRefunded - $creditEarnedReversed
                ));
                $customer->credit = $newCredit;
                $customer->credit_last_updated_at = now();
                $customer->last_warning_sent_at = null;
                $customer->save();
            }

            if ($settlement['ar'] >= 0.01 && $purchase->isInstallment()) {
                self::reduceUnpaidInstallments($purchase, $settlement['ar'], $customer);
            }
            if ($settlement['cheque'] >= 0.01) {
                self::reduceOutstandingCheque($purchase, $settlement['cheque']);
            }

            $purchase->credit_earned = max(0, PriceTools::roundToman($origEarned - $creditEarnedReversed));
            $origUsed = (float) $purchase->credit_used;
            $purchase->credit_used = max(0, PriceTools::roundToman($origUsed - PriceTools::roundToman($origUsed * $ratio)));

            if ($purchasedProduct->product_id && $purchasedProduct->product) {
                $purchasedProduct->product->increment('quantity', $returnQuantity);
                \App\Services\ProductStockNotifyService::afterQuantityIncrease(
                    $purchasedProduct->product,
                    (float) $returnQuantity
                );
            } else {
                app(ShopPosSaleService::class)->restoreStock($purchasedProduct, $returnQuantity);
            }

            $purchasedProductId = (int) $purchasedProduct->id;
            if (ProductQuantityTools::isFullReturn($returnQuantity, $lineQty)) {
                $purchasedProduct->delete();
            } else {
                $purchasedProduct->quantity = ProductQuantityTools::normalize($lineQty - $returnQuantity, $unitType);
                $purchasedProduct->save();
            }

            $logPayload = [
                'atelier_id' => $atelierId,
                'purchase_id' => $purchase->id,
                'purchased_product_id' => $purchasedProductId,
                'product_id' => $purchasedProduct->product_id,
                'produced_good_id' => $purchasedProduct->produced_good_id,
                'raw_material_id' => $purchasedProduct->raw_material_id,
                'quantity' => $returnQuantity,
                'sale_price' => $unitSale,
                'purchase_price' => $unitPurchase,
                'return_sale_total' => $returnAmount,
                'return_purchase_total' => $returnPurchaseTotal,
                'phone' => $purchase->phone,
                'payment_type' => $purchase->payment_type,
                'credit_used_refund' => $creditRefunded,
                'credit_earned_reversed' => $creditEarnedReversed,
                'size' => $purchasedProduct->size,
                'color' => $purchasedProduct->color,
                'user_name' => $userName,
                'notes' => $notes,
            ];
            if (Schema::hasColumn('purchase_item_returns', 'cash_refunded')) {
                $logPayload['cash_refunded'] = $cashRefunded;
            }
            if (Schema::hasColumn('purchase_item_returns', 'card_refunded')) {
                $logPayload['card_refunded'] = $cardRefunded;
            }
            if (Schema::hasColumn('purchase_item_returns', 'card_refund_destination')) {
                $logPayload['card_refund_destination'] = $options['card_refund_destination'];
            }
            if (Schema::hasColumn('purchase_item_returns', 'shop_account_id')) {
                $logPayload['shop_account_id'] = $options['shop_account_id'];
            }

            $log = PurchaseItemReturn::create($logPayload);

            self::logReturnedProduct(
                $purchasedProduct,
                $atelierId,
                $returnQuantity,
                $returnAmount,
                $returnPurchaseTotal,
                $userName,
                $notes,
                $purchase,
                $creditRefunded,
                $creditEarnedReversed
            );

            CustomerCreditExpenseService::recordPurchaseReturn(
                $purchase,
                $log,
                $creditRefunded,
                $creditEarnedReversed,
                $userName
            );

            AccountingReturnPoster::post($log, $settlement);

            $purchase->load('purchasedProducts');
            $purchase->syncAmountsFromRemainingLines();
            $purchase->save();
            $purchase->load('purchasedProducts.product', 'purchasedProducts.producedGood', 'purchasedProducts.rawMaterial');
            if ($customer) {
                $customer->refresh();
            }

            return [
                'log' => $log,
                'returned_item' => [
                    'product_id' => $purchasedProduct->product_id,
                    'product_name' => $purchasedProduct->display_name,
                    'quantity' => $returnQuantity,
                    'sale_price' => $unitSale,
                    'purchase_price' => $unitPurchase,
                    'return_amount' => $returnAmount,
                    'return_purchase_total' => $returnPurchaseTotal,
                    'credit_refunded' => $creditRefunded,
                    'credit_used_refund' => $creditRefunded,
                    'credit_earned_reversed' => $creditEarnedReversed,
                    'cash_refunded' => $cashRefunded,
                    'card_refunded' => $cardRefunded,
                    'card_refund_destination' => $options['card_refund_destination'],
                    'shop_account_id' => $options['shop_account_id'],
                    'unpaid_reduced' => round($settlement['ar'] + $settlement['cheque'], 2),
                ],
                'row' => PurchaseItemReturnGridService::formatTransactionRow(
                    $log->fresh(['product:id,name,barcode'])
                ),
                'phone' => $purchase->phone,
                'customer_credit' => $customer ? (float) $customer->credit : 0,
                'purchase' => $purchase,
            ];
        };

        if ($useTransaction) {
            return DB::transaction($run);
        }

        return $run();
    }

    /**
     * سهم برگشت: اول بدهی پرداخت‌نشده، نقد از صندوق، کارت به اعتبار یا حساب.
     *
     * @param  array{card_refund_destination: string, shop_account_id: int|null}  $options
     * @return array{
     *   loyalty: float,
     *   cash: float,
     *   card: float,
     *   card_credit: float,
     *   card_account: float,
     *   pos: float,
     *   wallet: float,
     *   ar: float,
     *   cheque: float,
     *   card_refund_destination: string,
     *   shop_account_id: int|null
     * }
     */
    public static function allocateReturnSettlement(
        Purchase $purchase,
        float $ratio,
        array $options = []
    ): array {
        $ratio = min(1, max(0, $ratio));
        $options = self::normalizeRefundOptions($options);
        $purchase->loadMissing(['installments', 'cheque']);

        $loyalty = PriceTools::roundToman((float) $purchase->credit_used * $ratio);
        [$cashBase, $cardBase] = self::paidCashCardBases($purchase);
        $cash = PriceTools::roundToman($cashBase * $ratio);
        $card = PriceTools::roundToman($cardBase * $ratio);

        $cardCredit = 0.0;
        $cardAccount = 0.0;
        $pos = 0.0;
        if ($card >= 0.01) {
            if ($options['card_refund_destination'] === self::CARD_REFUND_SHOP_ACCOUNT) {
                $cardAccount = $card;
            } elseif ($options['card_refund_destination'] === self::CARD_REFUND_POS_TERMINAL) {
                $pos = $card;
            } else {
                $cardCredit = $card;
            }
        }

        $ar = 0.0;
        $cheque = 0.0;

        if ($purchase->isInstallment()) {
            $unpaid = (float) $purchase->installments->where('is_paid', false)->sum('amount');
            $ar = round($unpaid * $ratio, 2);
        } elseif ($purchase->isDebt() && ! $purchase->isDebtSettled()) {
            $ar = round($purchase->outstandingDebtAmount() * $ratio, 2);
        } elseif ($purchase->isCheque() && ! $purchase->isChequeSettled()) {
            $cheque = round($purchase->outstandingChequeAmount() * $ratio, 2);
        }

        return [
            'loyalty' => $loyalty,
            'cash' => $cash,
            'card' => $card,
            'card_credit' => $cardCredit,
            'card_account' => $cardAccount,
            'pos' => $pos,
            'wallet' => PriceTools::roundToman($cash + $card),
            'ar' => $ar,
            'cheque' => $cheque,
            'card_refund_destination' => $options['card_refund_destination'],
            'shop_account_id' => $options['shop_account_id'],
        ];
    }

    /**
     * @param  array{card_refund_destination?: string, shop_account_id?: int|null}  $options
     * @return array{card_refund_destination: string, shop_account_id: int|null}
     */
    public static function normalizeRefundOptions(array $options): array
    {
        $destination = (string) ($options['card_refund_destination'] ?? self::CARD_REFUND_CUSTOMER_CREDIT);
        if (! in_array($destination, self::cardRefundDestinations(), true)) {
            $destination = self::CARD_REFUND_CUSTOMER_CREDIT;
        }

        $shopAccountId = isset($options['shop_account_id']) ? (int) $options['shop_account_id'] : null;
        if ($shopAccountId !== null && $shopAccountId <= 0) {
            $shopAccountId = null;
        }

        if ($destination === self::CARD_REFUND_SHOP_ACCOUNT && $shopAccountId) {
            $account = \App\Models\ShopAccount::query()->find($shopAccountId);
            if (! $account || $account->isTill()) {
                throw new \InvalidArgumentException('برای برگشت کارتخوان، حساب بانکی یا تنخواه معتبر انتخاب کنید (نه صندوق نقد).');
            }
        }

        return [
            'card_refund_destination' => $destination,
            'shop_account_id' => $destination === self::CARD_REFUND_SHOP_ACCOUNT ? $shopAccountId : null,
        ];
    }

    /**
     * نقد و کارتی که در برگشت باید مثل فروش عادی پرسیده شود.
     * وصول به صندوق = نقد. وصول به حساب بانکی یا تنخواه = کارت (سؤال مقصد برگشت).
     * برگشت‌های قبلی از همین مبلغ کم می‌شوند تا دوباره برگردانده نشود.
     *
     * @return array{0: float, 1: float}
     */
    public static function refundableCashCard(Purchase $purchase): array
    {
        $cash = (float) $purchase->cash_amount;
        $card = (float) $purchase->card_amount;

        $usedPayments = false;
        if (Schema::hasTable('purchase_debt_payments')) {
            $withAccount = Schema::hasColumn('purchase_debt_payments', 'shop_account_id');
            $purchase->loadMissing($withAccount ? 'debtPayments.shopAccount' : 'debtPayments');
            if ($purchase->debtPayments->isNotEmpty()) {
                $usedPayments = true;
                foreach ($purchase->debtPayments as $payment) {
                    $account = $withAccount && $payment->relationLoaded('shopAccount')
                        ? $payment->shopAccount
                        : null;
                    if ($account && ! $account->isTill()) {
                        $card += (float) $payment->cash_amount + (float) $payment->card_amount;
                    } else {
                        $cash += (float) $payment->cash_amount;
                        $card += (float) $payment->card_amount;
                    }
                }
            }
        }

        if (! $usedPayments) {
            $cash += (float) $purchase->debt_settled_cash_amount;
            $card += (float) $purchase->debt_settled_card_amount;
            if ($cash + $card < 0.01 && $purchase->isDebtSettled()) {
                $cash = (float) $purchase->cash_amount;
                $card = (float) $purchase->card_amount;
            }
        }

        if (Schema::hasTable('purchase_item_returns')
            && Schema::hasColumn('purchase_item_returns', 'cash_refunded')
        ) {
            $returned = PurchaseItemReturn::query()
                ->where('purchase_id', $purchase->id)
                ->selectRaw('COALESCE(SUM(cash_refunded), 0) as cash, COALESCE(SUM(card_refunded), 0) as card')
                ->first();
            $cash -= (float) ($returned->cash ?? 0);
            $card -= (float) ($returned->card ?? 0);
        }

        return [max(0, round($cash, 2)), max(0, round($card, 2))];
    }

    /**
     * پایهٔ نقد/کارت پرداخت‌شده روی فاکتور (قبل از نسبت برگشت).
     *
     * @return array{0: float, 1: float}
     */
    protected static function paidCashCardBases(Purchase $purchase): array
    {
        if ($purchase->isInstallment()) {
            $paid = (float) $purchase->installments->where('is_paid', true)->sum('amount');

            // وصول اقساط در حسابداری به صندوق نقد می‌رود
            return [max(0, $paid), 0.0];
        }

        if ($purchase->isDebt()) {
            return self::refundableCashCard($purchase);
        }

        $cash = (float) $purchase->cash_amount;
        $card = (float) $purchase->card_amount;
        if ($cash + $card >= 0.01) {
            return [max(0, $cash), max(0, $card)];
        }

        // فاکتورهای قدیمی بدون تفکیک نقد/کارت → نقد از صندوق
        $paid = max(0, round(
            (float) $purchase->total_amount
            - (float) $purchase->discount_amount
            - (float) $purchase->credit_used,
            2
        ));
        if ($purchase->isDebt() && ! $purchase->isDebtSettled()) {
            $paid = (float) $purchase->immediatePaidAmount();
        } elseif ($purchase->isCheque()) {
            $paid = (float) $purchase->immediatePaidAmount();
        }

        return [max(0, $paid), 0.0];
    }

    protected static function reduceUnpaidInstallments(Purchase $purchase, float $reduceBy, ?UserShiksho $customer): void
    {
        $left = round($reduceBy, 2);
        if ($left < 0.01) {
            return;
        }

        $unpaid = $purchase->installments
            ->where('is_paid', false)
            ->sortByDesc('installment_number')
            ->values();

        foreach ($unpaid as $row) {
            if ($left < 0.01) {
                break;
            }
            $amount = round((float) $row->amount, 2);
            $take = round(min($amount, $left), 2);
            $newAmount = round($amount - $take, 2);
            if ($newAmount < 0.01) {
                $row->delete();
            } else {
                $row->amount = $newAmount;
                $row->save();
            }
            $left = round($left - $take, 2);
        }

        $restored = round($reduceBy - $left, 2);
        if ($customer && $restored >= 0.01) {
            $customer->installment_credit = round((float) $customer->installment_credit + $restored, 2);
            $customer->save();
        }

        $purchase->unsetRelation('installments');
        $purchase->load('installments');
        $remainingUnpaid = $purchase->installments->where('is_paid', false);
        $purchase->installment_count = max(1, (int) $purchase->installments->count());
        $purchase->installment_amount = $remainingUnpaid->isEmpty()
            ? 0
            : round((float) $remainingUnpaid->avg('amount'), 2);
    }

    protected static function reduceOutstandingCheque(Purchase $purchase, float $reduceBy): void
    {
        $reduceBy = round($reduceBy, 2);
        if ($reduceBy < 0.01 || ! $purchase->cheque) {
            return;
        }

        $cheque = $purchase->cheque;
        if ($cheque->status === Cheque::STATUS_CLEARED) {
            return;
        }

        $newAmount = round((float) $cheque->amount - $reduceBy, 2);
        if ($newAmount < 0.01) {
            $cheque->update([
                'amount' => 0,
                'status' => Cheque::STATUS_CANCELLED,
                'purchase_id' => null,
            ]);
            $purchase->cheque_id = null;
        } else {
            $cheque->update(['amount' => $newAmount]);
        }
    }

    protected static function resolveCustomer(Purchase $purchase, ?string $phoneInput, int $atelierId): UserShiksho
    {
        $raw = $purchase->phone ?: $phoneInput;
        $phone = PhoneTools::normalizeIranPhone($raw);
        if (! PhoneTools::isValidIranMobile($phone)) {
            throw new \InvalidArgumentException(
                'این خرید مشتری ندارد. برای برگشت، شماره موبایل را بفرستید تا کاربر ساخته شود و اعتبار به همان حساب برگردد.'
            );
        }

        if (! $purchase->phone) {
            $purchase->phone = $phone;
            $purchase->save();
        }

        $user = UserShiksho::query()
            ->where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->first();

        if ($user) {
            return $user;
        }

        return UserShiksho::create([
            'phone' => $phone,
            'atelier_id' => $atelierId,
            'credit' => 0,
            'installment_credit' => 0,
            'credit_last_updated_at' => now(),
            'last_warning_sent_at' => null,
        ]);
    }

    protected static function logReturnedProduct(
        PurchasedProduct $purchasedProduct,
        int $atelierId,
        float $quantity,
        float $returnSaleTotal,
        float $returnPurchaseTotal,
        ?string $userName,
        ?string $notes,
        Purchase $purchase,
        float $creditRefunded,
        float $creditEarnedReversed
    ): void {
        if (! Schema::hasTable('returned_products')) {
            return;
        }

        $productId = $purchasedProduct->product_id;
        if (! $productId) {
            return;
        }

        $payload = [
            'product_id' => $productId,
            'atelier_id' => $atelierId,
            'sale_price' => $returnSaleTotal,
            'purchase_price' => $returnPurchaseTotal,
            'user_name' => $userName,
            'notes' => $notes,
        ];
        if (Schema::hasColumn('returned_products', 'purchase_id')) {
            $payload['purchase_id'] = $purchase->id;
        }
        if (Schema::hasColumn('returned_products', 'phone')) {
            $payload['phone'] = $purchase->phone;
        }
        if (Schema::hasColumn('returned_products', 'quantity')) {
            $payload['quantity'] = $quantity;
        }
        if (Schema::hasColumn('returned_products', 'credit_refunded')) {
            $payload['credit_refunded'] = $creditRefunded;
        }
        if (Schema::hasColumn('returned_products', 'credit_earned_reversed')) {
            $payload['credit_earned_reversed'] = $creditEarnedReversed;
        }

        ReturnedProduct::create($payload);
    }

    protected static function findUserShiksho(Purchase $purchase): ?UserShiksho
    {
        if (! $purchase->phone) {
            return null;
        }
        $query = UserShiksho::where('phone', $purchase->phone);
        if ($purchase->atelier_id !== null) {
            $query->where('atelier_id', $purchase->atelier_id);
        }

        return $query->first();
    }
}
