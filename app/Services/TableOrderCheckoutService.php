<?php

namespace App\Services;

use App\Exceptions\InsufficientShopSmsQuotaException;
use App\Models\CustomerPhone;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchasedProduct;
use App\Models\Setting;
use App\Models\TableOrder;
use App\Models\UserShiksho;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TableOrderCheckoutService
{
    /**
     * تبدیل سفارش پای میز به خرید واقعی بعد از پرداخت.
     */
    public function pay(TableOrder $tableOrder, Request $request): Purchase
    {
        return DB::transaction(function () use ($tableOrder, $request) {
            $order = TableOrder::query()->where('id', $tableOrder->id)->lockForUpdate()->first();
            if (! $order || ! $order->isPending()) {
                abort(response()->json(['message' => 'این سفارش قابل پرداخت نیست.'], 422));
            }

            $order->load('items.product');
            $atelierId = (int) $order->atelier_id;
            Setting::setShopContext($atelierId);

            foreach ($order->items as $line) {
                $product = $line->product;
                if (! $product) {
                    abort(response()->json(['message' => 'یک یا چند محصول یافت نشد'], 404));
                }
                if ((float) $product->quantity < (float) $line->quantity) {
                    abort(response()->json([
                        'message' => "موجودی محصول «{$product->name}» کافی نیست.",
                    ], 400));
                }
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
                    $creditUsed = min((float) $userShiksho->credit, $grossTotal);
                    $userShiksho->useCredit($creditUsed);
                }
            }

            $payable = max(0, round($grossTotal - $creditUsed, 2));
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

            foreach ($order->items as $line) {
                PurchasedProduct::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $line->product_id,
                    'quantity' => $line->quantity,
                    'purchase_price' => $line->purchase_price,
                    'sale_price' => $line->sale_price,
                    'size' => $line->size,
                    'color' => $line->color,
                ]);

                Product::where('id', $line->product_id)->decrement('quantity', $line->quantity);
            }

            if ($phone) {
                if ($enableLoyaltyCredit && $creditEarned > 0) {
                    UserShiksho::updateCredit($phone, $creditEarned, $atelierId);
                    $creditFormatted = number_format($creditEarned, 0);
                    $shopName = SmsTools::shopSmsBrand($atelierId);
                    $text = "{$shopName}\nهمراه عزیز مبلغ {$creditFormatted} تومان به اعتبار شما برای خرید بعدی اضافه شد";
                    try {
                        SmsTools::sendShopSms($phone, $text, (string) $purchase->id, $creditEarned, 'credit', $atelierId);
                    } catch (InsufficientShopSmsQuotaException $e) {
                        //
                    }
                }
                CustomerPhone::createNewPhone($phone);
            }

            $order->update([
                'status' => TableOrder::STATUS_PAID,
                'purchase_id' => $purchase->id,
                'use_credit' => $useCredit,
            ]);

            return $purchase;
        });
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
