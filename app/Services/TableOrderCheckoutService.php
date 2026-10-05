<?php

namespace App\Services;

use App\Exceptions\InsufficientShopSmsQuotaException;
use App\Models\CustomerPhone;
use App\Models\GatewayPayment;
use App\Models\Purchase;
use App\Models\PurchasedProduct;
use App\Models\Setting;
use App\Models\TableOrder;
use App\Models\UserShiksho;
use App\Services\CustomerCreditExpenseService;
use App\Tools\SmsTools;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TableOrderCheckoutService
{
    public function __construct(
        protected ShopPosSaleService $posSale,
    ) {
    }

    /**
     * ردیف‌های ورودی ShopPosSaleService از اقلام سفارش (قیمت همان قیمت زمان سفارش).
     *
     * @return array<int, array<string, mixed>>
     */
    public function prepareOrderLines(TableOrder $order): array
    {
        $rows = [];
        foreach ($order->items as $line) {
            $row = [
                'quantity' => (float) $line->quantity,
                'size' => $line->size,
                'color' => $line->color,
            ];
            if (! empty($line->produced_good_id)) {
                $row['produced_good_id'] = (int) $line->produced_good_id;
            } elseif (! empty($line->raw_material_id)) {
                $row['raw_material_id'] = (int) $line->raw_material_id;
            } else {
                $row['product_id'] = (int) $line->product_id;
            }
            $rows[] = $row;
        }

        try {
            $prepared = $this->posSale->prepareLines($rows, (int) $order->atelier_id);
        } catch (RuntimeException $e) {
            abort(response()->json(['message' => $e->getMessage()], 422));
        }

        foreach ($order->items->values() as $i => $line) {
            $prepared[$i]['sale_price'] = (float) $line->sale_price;
        }

        return $prepared;
    }

    /**
     * تبدیل سفارش پای میز به خرید واقعی بعد از پرداخت.
     */
    public function pay(TableOrder $tableOrder, Request $request): Purchase
    {
        return DB::transaction(function () use ($tableOrder, $request) {
            $atelierId = (int) $tableOrder->atelier_id;
            AccountingVoucherService::lockAtelier($atelierId);

            $order = TableOrder::query()->where('id', $tableOrder->id)->lockForUpdate()->first();
            if (! $order || ! $order->isPending()) {
                abort(response()->json(['message' => 'این سفارش قابل پرداخت نیست.'], 422));
            }

            $order->load('items.product');
            $atelierId = (int) $order->atelier_id;
            Setting::setShopContext($atelierId);

            $prepared = $this->prepareOrderLines($order);
            try {
                $this->posSale->assertStock($prepared);
            } catch (RuntimeException $e) {
                abort(response()->json(['message' => $e->getMessage()], 400));
            }

            $grossTotal = (float) $order->total_amount;
            $useCredit = $request->has('use_credit')
                ? $request->boolean('use_credit')
                : (bool) $order->use_credit;

            $creditUsed = 0.0;
            $phone = $order->phone;
            if ($phone && $useCredit) {
                $userShiksho = UserShiksho::where('phone', $phone)
                    ->where('atelier_id', $atelierId)
                    ->lockForUpdate()
                    ->first();
                if ($userShiksho && $userShiksho->credit > 0) {
                    $creditUsed = \App\Tools\PriceTools::roundToman(min((float) $userShiksho->credit, $grossTotal));
                    $userShiksho->useCredit($creditUsed);
                }
            }

            $payable = max(0, \App\Tools\PriceTools::roundToman($grossTotal - $creditUsed));
            $settlement = $this->resolveSettlement($request, $payable, $order->payment_method);

            $creditEarned = 0.0;
            $enableLoyaltyCredit = Setting::isEnabled('enable_loyalty_credit', true);
            if ($phone && $enableLoyaltyCredit) {
                $creditEarned = UserShiksho::calculateCredit($grossTotal, $atelierId);
            }

            $purchase = Purchase::create([
                'atelier_id' => $atelierId,
                'shop_table_id' => $order->shop_table_id,
                'table_label' => $order->table_label,
                'phone' => $phone,
                'total_amount' => $grossTotal,
                'credit_used' => $creditUsed,
                'credit_earned' => $creditEarned,
                'payment_type' => 'cash',
                'card_amount' => $settlement['card_amount'],
                'cash_amount' => $settlement['cash_amount'],
                'is_debt_settled' => false,
                'debt_settlement_note' => $request->input('note', $order->note),
            ]);
            \App\Services\DailyTicketNumberService::assign($purchase);
            CustomerCreditExpenseService::recordCreditUsed($purchase);

            $createdLines = [];
            foreach ($prepared as $line) {
                $createdLines[] = PurchasedProduct::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $line['product_id'],
                    'produced_good_id' => $line['produced_good_id'],
                    'raw_material_id' => $line['raw_material_id'],
                    'item_name' => $line['item_name'],
                    'quantity' => $line['quantity'],
                    'purchase_price' => $line['purchase_price'],
                    'sale_price' => $line['sale_price'],
                    'size' => $line['size'] ?? null,
                    'color' => $line['color'] ?? null,
                ]);
            }

            try {
                $this->posSale->commitStock($prepared, $createdLines);
            } catch (RuntimeException $e) {
                abort(response()->json(['message' => $e->getMessage()], 409));
            }

            if ($phone) {
                if ($enableLoyaltyCredit && $creditEarned > 0) {
                    UserShiksho::updateCredit($phone, $creditEarned, $atelierId);
                    $creditFormatted = number_format($creditEarned, 0);
                    $shopName = SmsTools::shopSmsBrand($atelierId);
                    $text = "{$shopName}\nهمراه عزیز مبلغ {$creditFormatted} تومان به اعتبار شما برای خرید بعدی اضافه شد";
                    try {
                        SmsTools::sendShopSms($phone, $text, (string) $purchase->id, $creditEarned, 'credit', $atelierId);
                        $purchase->setAttribute('sms_sent', true);
                        $purchase->setAttribute('sms_quota_exhausted', false);
                    } catch (InsufficientShopSmsQuotaException $e) {
                        $purchase->setAttribute('sms_sent', false);
                        $purchase->setAttribute('sms_quota_exhausted', true);
                        $purchase->setAttribute('sms_error', InsufficientShopSmsQuotaException::sideEffectNotice());
                    }
                }
                CustomerPhone::createNewPhone($phone);
            }

            $order->update([
                'status' => TableOrder::STATUS_PAID,
                'purchase_id' => $purchase->id,
                'use_credit' => $useCredit,
            ]);

            $purchase->load(['purchasedProducts', 'cheque']);
            AccountingSalePoster::post($purchase);

            return $purchase;
        }, 5);
    }

    /**
     * بعد از تأیید زرین‌پال: سفارش علامت «پرداخت آنلاین» می‌خورد و خودکار فاکتور می‌شود.
     * اگر ساخت فاکتور خطا بدهد، پرداخت درگاه حفظ می‌شود و سفارش برای تسویهٔ دستی پرسنل می‌ماند.
     */
    public function fulfillOnlinePayment(GatewayPayment $payment, ?string $refId): void
    {
        $orderId = (int) ($payment->meta['table_order_id'] ?? $payment->item_id);
        $order = TableOrder::query()->where('id', $orderId)->lockForUpdate()->first();
        if (! $order) {
            throw new RuntimeException('سفارش پرداخت‌شده یافت نشد.');
        }

        if (TableOrder::hasOnlineColumns()) {
            $updates = [
                'gateway_payment_id' => $payment->id,
                'online_paid_at' => now(),
                'online_ref_id' => $refId,
            ];
            // پرداخت بعد از لغو خودکار (انقضای مهلت) رسید: سفارش دوباره فعال شود.
            if ($order->status === TableOrder::STATUS_CANCELLED && ! $order->purchase_id) {
                $updates['status'] = TableOrder::STATUS_PENDING;
                $updates['cancelled_by'] = null;
                $updates['cancelled_at'] = null;
            }
            $order->update($updates);
        }

        if (! $order->isPending() || $order->purchase_id) {
            return;
        }

        $note = trim(implode(' | ', array_filter([
            $order->note,
            'پرداخت آنلاین زرین‌پال'.($refId ? ' - کد پیگیری '.$refId : ''),
        ])));

        $request = Request::create('/', 'POST', [
            'payment_settlement' => 'card',
            'note' => mb_substr($note, 0, 500),
        ]);

        try {
            $this->pay($order, $request);
        } catch (HttpResponseException $e) {
            $this->logFulfillFailure($order, $payment, $e->getResponse()->getContent());
        } catch (\Throwable $e) {
            report($e);
            $this->logFulfillFailure($order, $payment, $e->getMessage());
        }
    }

    private function logFulfillFailure(TableOrder $order, GatewayPayment $payment, $reason): void
    {
        Log::warning('table_order.online_fulfill_failed', [
            'table_order_id' => $order->id,
            'gateway_payment_id' => $payment->id,
            'reason' => is_string($reason) ? mb_substr($reason, 0, 500) : $reason,
        ]);
    }

    /**
     * @return array{card_amount: float, cash_amount: float}
     */
    private function resolveSettlement(Request $request, float $payable, ?string $paymentMethod): array
    {
        if ($payable <= 0) {
            return ['card_amount' => 0.0, 'cash_amount' => 0.0];
        }

        $card = (float) $request->input('card_amount', 0);
        $cash = (float) $request->input('cash_amount', 0);
        $settlement = $request->input('payment_settlement');

        if ($card <= 0 && $cash <= 0) {
            if ($settlement === 'cash') {
                $cash = $payable;
            } elseif ($settlement === 'card' || in_array($paymentMethod, [
                TableOrder::METHOD_ONLINE,
                TableOrder::METHOD_CARD_TO_CARD,
                TableOrder::METHOD_POS,
            ], true)) {
                $card = $payable;
            } else {
                $cash = $payable;
            }
        }

        if (abs(($card + $cash) - $payable) > 0.02) {
            abort(response()->json([
                'message' => 'جمع مبلغ کارت و نقد باید برابر مبلغ قابل پرداخت باشد.',
                'payable_amount' => $payable,
                'card_amount' => $card,
                'cash_amount' => $cash,
            ], 422));
        }

        return [
            'card_amount' => round($card, 2),
            'cash_amount' => round($cash, 2),
        ];
    }
}
