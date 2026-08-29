<?php

namespace App\Http\Controllers;

use App\Models\PurchasedProduct;
use App\Models\Purchase;
use App\Models\Product;
use App\Models\Installment;
use App\Models\Cheque;
use App\Models\UserShiksho;
use App\Models\CustomerPhone;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Services\ShopPosSaleService;
use App\Tools\PriceTools;
use App\Tools\PhoneTools;
use App\Tools\ProductQuantityTools;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Morilog\Jalali\Jalalian;


class PurchasedProductController extends Controller
{
    public function index(Request $request)
{
    $atelierId = $this->shopAtelierIdOrAbort($request);
    \App\Models\Setting::setShopContext($atelierId);

    // فقط Purchase هایی که:
    // 1. cart_id ندارند (فروش فیزیکی مستقیم)
    // 2. یا cart_id دارند و Cart status آن‌ها shipped است
    // 3. مجموع مبلغشان بیشتر از 0 است
    $query = Purchase::with([
            'purchasedProducts.product',
            'purchasedProducts.producedGood',
            'purchasedProducts.rawMaterial',
            'installments',
            'cheque',
        ])
        ->where('atelier_id', $atelierId)
        ->where('total_amount', '>', 0) // فقط خریدهایی که مجموع مبلغشان بیشتر از 0 است
        ->where(function($q) {
            $q->whereNull('cart_id') // فروش فیزیکی
              ->orWhereHas('cart', function($cartQuery) {
                  $cartQuery->where('status', Cart::STATUS_SHIPPED); // سفارش اینترنتی که shipped شده
              });
        })
        ->orderBy('id', 'desc');

    // جستجو بر اساس searchFilterModel
    $searchDataModel = json_decode($request->input('searchFilterModel'));
    if ($searchDataModel) {
        $query->where(function($q) use ($searchDataModel) {
            if (is_object($searchDataModel)) {
                // جستجو بر اساس شماره تلفن
                if (isset($searchDataModel->phone)) {
                    $q->where('phone', 'like', '%' . $searchDataModel->phone . '%');
                }
            } else if (is_string($searchDataModel)) {
                // اگر یک رشته ساده بود، در شماره تلفن جستجو می‌کند
                $q->where('phone', 'like', '%' . $searchDataModel . '%');
            }
        });
    }

    // فیلتر تاریخ
    if ($request->has('filter')) {
        if ($request->filter === 'today') {
            $query->whereDate('created_at', Carbon::today());
        } elseif ($request->filter === 'week') {
            // فیلتر هفته شمسی (شنبه تا جمعه)
            $now = Jalalian::now();
            $dayOfWeek = $now->getDayOfWeek(); // 0 = شنبه, 6 = جمعه
            $startOfWeek = Jalalian::now()->subDays($dayOfWeek)->toCarbon()->startOfDay();
            $endOfWeek = Jalalian::now()->addDays(6 - $dayOfWeek)->toCarbon()->endOfDay();
            $query->whereBetween('created_at', [$startOfWeek, $endOfWeek]);
        } elseif ($request->filter === 'month') {
            $query->whereMonth('created_at', Carbon::now()->month)
                  ->whereYear('created_at', Carbon::now()->year);
        } elseif ($request->filter === 'range') {
            // فیلتر بازه تاریخ شمسی
            if ($request->has('from_date')) {
                $fromDate = json_decode($request->input('from_date'));
                $fromCarbon = (new Jalalian($fromDate->year, $fromDate->month, $fromDate->day))->toCarbon()->startOfDay();
                $query->where('created_at', '>=', $fromCarbon);
            }
            if ($request->has('to_date')) {
                $toDate = json_decode($request->input('to_date'));
                $toCarbon = (new Jalalian($toDate->year, $toDate->month, $toDate->day))->toCarbon()->endOfDay();
                $query->where('created_at', '<=', $toCarbon);
            }
        }
    }

    $items = $query->paginate();

    // محاسبه مجموع مبلغ واقعی پرداخت شده برای آیتم‌های این صفحه
    $total = 0;
    foreach ($items->items() as $purchase) {
        // برای خریدهای اقساطی: مجموع قسط‌های پرداخت شده
        // برای خریدهای نقدی: مبلغ کل خرید
        if ($purchase->isInstallment()) {
            $total += $purchase->paid_amount;
        } elseif ($purchase->isDebt()) {
            $total += $purchase->isDebtSettled()
                ? ((float) $purchase->debt_settled_card_amount + (float) $purchase->debt_settled_cash_amount)
                : $purchase->payableAmount();
        } elseif ($purchase->isCheque()) {
            $total += $purchase->immediatePaidAmount()
                + ($purchase->isChequeSettled() ? $purchase->chequeAmount() : 0);
        } else {
            $total += $purchase->total_amount;
        }
    }

    // اضافه کردن به meta به شکل درست
    $items->withPath(url()->current()); // حفظ مسیر URL

    // تبدیل به array و اضافه کردن فیلد paid_amount برای خریدهای اقساطی
    $itemsArray = $items->toArray();
    foreach ($itemsArray['data'] as &$purchaseData) {
        $purchase = $items->firstWhere('id', $purchaseData['id']);
        if ($purchase && $purchase->isInstallment()) {
            // اضافه کردن فیلد paid_amount برای خریدهای اقساطی
            $purchaseData['paid_amount'] = (float) $purchase->paid_amount;
        }
        if ($purchase && $purchase->isDebt()) {
            $purchaseData['payable_amount'] = $purchase->payableAmount();
            $purchaseData['is_debt_settled'] = (bool) $purchase->is_debt_settled;
            $purchaseData['payment_type_label'] = 'قرضی';
        }
        if ($purchase && $purchase->isCheque()) {
            $purchaseData['payable_amount'] = $purchase->payableAmount();
            $purchaseData['is_cheque_settled'] = $purchase->isChequeSettled();
            $purchaseData['outstanding_cheque_amount'] = $purchase->outstandingChequeAmount();
            $purchaseData['cheque_amount'] = $purchase->chequeAmount();
            $purchaseData['immediate_paid_amount'] = $purchase->immediatePaidAmount();
            $purchaseData['payment_type_label'] = $purchase->immediatePaidAmount() > 0.02
                ? 'نقد/کارت + چک'
                : 'چکی';
            $purchaseData['cheque_id'] = $purchase->cheque_id;
        }
    }
    unset($purchaseData);

    $itemsArray['total_purchase_price'] = $total;

    return response($itemsArray, 200);
}


    public function store(Request $request)
    {
        $this->bindShopSettingAtelierFromRequest($request);

        if ($request->has('phone')) {
            $request->merge([
                'phone' => PhoneTools::normalizeIranPhone($request->input('phone')),
            ]);
        }

        $request->validate([
            'client_id' => 'nullable|string|max:64',
            'phone' => 'required_if:payment_type,debt|nullable|string|regex:/^09\d{9}$/',
            'products' => 'required|array|min:1',
            'products.*.product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'products.*.produced_good_id' => array_values(array_filter([
                'nullable',
                'integer',
                Schema::hasTable('produced_goods') ? 'exists:produced_goods,id' : null,
            ])),
            'products.*.raw_material_id' => array_values(array_filter([
                'nullable',
                'integer',
                Schema::hasTable('raw_materials') ? 'exists:raw_materials,id' : null,
            ])),
            'products.*.quantity' => 'required|numeric|min:0.001',
            'products.*.sale_price' => 'nullable|numeric|min:0',
            'products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'products.*.size' => 'nullable|string|max:255',
            'products.*.color' => 'nullable|string|max:255',
            'use_credit' => 'nullable|boolean',
            'discount_amount' => 'nullable|numeric|min:0',
            'payment_type' => 'nullable|string|in:cash,installment,debt,cheque',
            'cheque_id' => 'required_if:payment_type,cheque|nullable|integer|exists:cheques,id',
            'installment_count' => 'required_if:payment_type,installment|integer|min:2|max:24',
            'card_amount' => 'nullable|numeric|min:0',
            'cash_amount' => 'nullable|numeric|min:0',
            'payment_settlement' => 'nullable|string|in:card,cash',
        ]);

        $phone = $request->input('phone');
        $useCredit = $request->input('use_credit', false);
        $paymentType = $request->input('payment_type', 'cash'); // پیش‌فرض: نقدی
        $installmentCount = $request->input('installment_count');
        $chequeId = $request->input('cheque_id');

        $staffAtelierId = $this->staffShopAtelierId($request);
        $posSale = app(ShopPosSaleService::class);
        try {
            $preparedLines = $posSale->prepareLines($request->input('products'), $staffAtelierId);
            $posSale->assertStock($preparedLines);
        } catch (\RuntimeException $e) {
            $status = strpos($e->getMessage(), 'یافت نشد') !== false ? 404 : 422;
            if (strpos($e->getMessage(), 'موجودی') !== false) {
                $status = 400;
            }

            return response(['error' => $e->getMessage()], $status);
        }

        $purchaseAtelierId = $posSale->purchaseAtelierId($preparedLines, $staffAtelierId);

        $clientId = $this->normalizeClientId($request->input('client_id'));
        if ($clientId !== null) {
            $existingPurchase = $this->findPurchaseByClientId($purchaseAtelierId, $clientId);
            if ($existingPurchase) {
                return $this->storePurchaseResponse($existingPurchase, true);
            }
        }

        // محاسبه مجموع مبلغ خرید بر اساس sale_price (با در نظر گیری تخفیف)
        $originalTotalAmount = 0;
        $productsData = [];
        foreach ($preparedLines as $line) {
            $originalTotalAmount += $line['quantity'] * $line['sale_price'];
            $productsData[] = [
                'product_id' => $line['product_id'],
                'produced_good_id' => $line['produced_good_id'],
                'raw_material_id' => $line['raw_material_id'],
                'item_name' => $line['item_name'],
                'quantity' => $line['quantity'],
                'sale_price' => $line['sale_price'],
                'purchase_price' => $line['purchase_price'],
                'size' => $line['size'] ?? null,
                'color' => $line['color'] ?? null,
            ];
        }

        // دریافت مبلغ تخفیف مستقیم (اگر وجود داشته باشد)
        $discountAmount = $request->input('discount_amount', 0);
        if ($discountAmount < 0) {
            $discountAmount = 0;
        }
        
        // مبلغ کل فاکتور (قبل از تخفیف فاکتور و اعتبار)
        $grossTotal = $originalTotalAmount;
        $amountAfterDiscount = max(0, $grossTotal - $discountAmount);
        $creditUsed = 0;
        $userShiksho = null;

        // اگر شماره تلفن وجود دارد و کاربر می‌خواهد از اعتبار استفاده کند
        if ($phone && $useCredit) {
            $userShikshoQuery = UserShiksho::where('phone', $phone);
            if ($purchaseAtelierId !== null) {
                $userShikshoQuery->where('atelier_id', $purchaseAtelierId);
            }
            $userShiksho = $userShikshoQuery->first();
            if ($userShiksho && $userShiksho->credit > 0) {
                $creditUsed = min($userShiksho->credit, $amountAfterDiscount);
                $userShiksho->useCredit($creditUsed);
            }
        }

        $payableAmount = max(0, $amountAfterDiscount - $creditUsed);

        $creditEarned = 0;
        
        // اگر تخفیف مستقیم داده نشده باشد و شماره تلفن وجود دارد و اعتبار فعال است، اعتبار جدید را محاسبه کن
        // اگر discount_amount > 0 باشد، اعتبار اضافه نمی‌شود
        $enableLoyaltyCredit = \App\Models\Setting::isEnabled('enable_loyalty_credit', true);
        if ($phone && $enableLoyaltyCredit && $discountAmount == 0) {
            // محاسبه اعتبار کسب شده (بر اساس مبلغ اصلی خرید، قبل از کسر اعتبار استفاده شده)
            $creditEarned = UserShiksho::calculateCredit($originalTotalAmount, $purchaseAtelierId);
        }

        // محاسبه مبلغ هر قسط در صورت اقساطی بودن (با در نظر گیری سود ماهانه)
        $installmentAmount = null;
        $finalTotalAmount = $grossTotal;
        
        if ($paymentType === 'installment' && $installmentCount) {
            // خواندن نرخ سود ماهانه از ستینگ (پیش‌فرض: 0 یعنی بدون سود)
            $monthlyInterestRate = (float) \App\Models\Setting::get('installment_monthly_interest_rate', 0);
            
            // یک سوم مبلغ به صورت نقد (قسط اول)
            $firstInstallmentAmount = $this->roundToThreeZeroEnding($payableAmount / 3);
            
            // بقیه مبلغ (دو سوم)
            $remainingAmount = $payableAmount - $firstInstallmentAmount;
            
            // تعداد ماه‌های باقیمانده (بعد از قسط اول)
            $remainingMonths = $installmentCount - 1;
            
            if ($monthlyInterestRate > 0 && $remainingMonths > 0) {
                // محاسبه سود: مبلغ باقیمانده × نرخ سود × تعداد ماه‌های باقیمانده
                $totalInterest = $remainingAmount * ($monthlyInterestRate / 100) * $remainingMonths;
                
                // مبلغ کل با سود = مبلغ باقیمانده + سود
                $remainingAmountWithInterest = $remainingAmount + $totalInterest;
                
                // مبلغ کل نهایی = قسط اول + بقیه با سود
                $finalTotalAmount = $this->roundToThreeZeroEnding($firstInstallmentAmount + $remainingAmountWithInterest);
                
                // مبلغ هر قسط باقیمانده
                $installmentAmount = $this->roundToThreeZeroEnding($remainingAmountWithInterest / $remainingMonths);
            } else {
                // بدون سود
                $finalTotalAmount = $this->roundToThreeZeroEnding($payableAmount);
                // مبلغ هر قسط باقیمانده
                $installmentAmount = $this->roundToThreeZeroEnding($remainingAmount / $remainingMonths);
            }
        }

        $amountPaidNow = $paymentType === 'installment'
            ? $this->roundToThreeZeroEnding($finalTotalAmount / 3)
            : ($paymentType === 'debt' ? 0.0 : (float) $payableAmount);

        $settlement = $paymentType === 'debt'
            ? ['card_amount' => 0.0, 'cash_amount' => 0.0]
            : ['card_amount' => 0.0, 'cash_amount' => 0.0];

        $linkedCheque = null;
        if ($paymentType === 'cheque') {
            if (! $chequeId) {
                return response(['error' => 'برای فروش چکی، cheque_id الزامی است.'], 422);
            }

            $linkedCheque = Cheque::find($chequeId);
            if (!$linkedCheque) {
                return response(['error' => 'چک یافت نشد.'], 404);
            }
            if ($linkedCheque->type !== Cheque::TYPE_RECEIVED) {
                return response(['error' => 'فقط چک دریافتی قابل اتصال به فروش است.'], 422);
            }
            if ($linkedCheque->status !== Cheque::STATUS_PENDING) {
                return response(['error' => 'فقط چک در انتظار وصول قابل اتصال به فروش است.'], 422);
            }
            if ($linkedCheque->purchase_id) {
                return response(['error' => 'این چک قبلاً به فروش دیگری وصل شده است.'], 422);
            }
            if ($purchaseAtelierId !== null && (int) $linkedCheque->atelier_id !== (int) $purchaseAtelierId) {
                return response(['error' => 'چک متعلق به این فروشگاه نیست.'], 422);
            }

            $chequeAmount = round((float) $linkedCheque->amount, 2);
            if ($chequeAmount <= 0) {
                return response(['error' => 'مبلغ چک نامعتبر است.'], 422);
            }
            if ($chequeAmount > $payableAmount + 0.02) {
                return response([
                    'error' => 'مبلغ چک بیشتر از مبلغ قابل پرداخت فروش است.',
                    'cheque_amount' => $chequeAmount,
                    'payable_amount' => (float) $payableAmount,
                ], 422);
            }

            // بخش نقد/کارت = باقیمانده بعد از چک (می‌تواند صفر باشد = تمام‌چکی)
            $immediateDue = round(max(0, (float) $payableAmount - $chequeAmount), 2);
            if ($immediateDue <= 0.02) {
                $settlement = ['card_amount' => 0.0, 'cash_amount' => 0.0];
            } else {
                // اگر فقط یکی از نقد/کارت ارسال شده یا payment_settlement، باقیمانده را پر می‌کند
                $settlement = $this->resolvePurchaseSettlement($request, $immediateDue);
            }

            if (abs($chequeAmount + $settlement['card_amount'] + $settlement['cash_amount'] - (float) $payableAmount) > 0.02) {
                return response([
                    'error' => 'جمع نقد + کارت + چک باید برابر مبلغ قابل پرداخت باشد.',
                    'payable_amount' => (float) $payableAmount,
                    'cheque_amount' => $chequeAmount,
                    'card_amount' => $settlement['card_amount'],
                    'cash_amount' => $settlement['cash_amount'],
                ], 422);
            }
        } elseif ($paymentType !== 'debt') {
            $settlement = $this->resolvePurchaseSettlement($request, $amountPaidNow);
        }

        // ایجاد سبد خرید (Purchase)
        try {
            DB::beginTransaction();

            if ($paymentType === 'cheque') {
                $linkedCheque = Cheque::query()
                    ->where('id', $chequeId)
                    ->where('type', Cheque::TYPE_RECEIVED)
                    ->where('status', Cheque::STATUS_PENDING)
                    ->whereNull('purchase_id')
                    ->lockForUpdate()
                    ->first();

                if (!$linkedCheque) {
                    DB::rollBack();

                    return response(['error' => 'چک برای اتصال به فروش در دسترس نیست.'], 422);
                }
            }

            $purchase = Purchase::create([
                'phone' => $phone,
                'total_amount' => $paymentType === 'installment' ? $finalTotalAmount : $grossTotal,
                'discount_amount' => round((float) $discountAmount, 2),
                'credit_used' => $creditUsed,
                'credit_earned' => $creditEarned,
                'payment_type' => $paymentType,
                'cheque_id' => $paymentType === 'cheque' ? $linkedCheque->id : null,
                'card_amount' => $settlement['card_amount'],
                'cash_amount' => $settlement['cash_amount'],
                'is_debt_settled' => false,
                'installment_count' => $paymentType === 'installment' ? $installmentCount : null,
                'installment_amount' => $installmentAmount,
                'atelier_id' => $purchaseAtelierId,
                'client_id' => $clientId,
            ]);

            if ($paymentType === 'cheque' && $linkedCheque) {
                $linkedCheque->update(['purchase_id' => $purchase->id]);
            }

            $purchasedProducts = [];
            foreach ($productsData as $productData) {
                $purchasedProducts[] = PurchasedProduct::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $productData['product_id'],
                    'produced_good_id' => $productData['produced_good_id'],
                    'raw_material_id' => $productData['raw_material_id'],
                    'item_name' => $productData['item_name'],
                    'quantity' => $productData['quantity'],
                    'purchase_price' => $productData['purchase_price'],
                    'sale_price' => $productData['sale_price'],
                    'size' => $productData['size'] ?? null,
                    'color' => $productData['color'] ?? null,
                ]);
            }

            $posSale->commitStock($preparedLines, $purchasedProducts);
            if ($creditUsed > 0) {
                $actor = $this->shopRequestActor($request);
                $actorName = $actor ? trim($actor->name.' '.($actor->last_name ?? '')) : null;
                \App\Services\CustomerCreditExpenseService::recordCreditUsed($purchase, $actorName);
            }
            DB::commit();
        } catch (QueryException $e) {
            DB::rollBack();
            if ($clientId !== null && $this->isDuplicateClientIdException($e)) {
                $existingPurchase = $this->findPurchaseByClientId($purchaseAtelierId, $clientId);
                if ($existingPurchase) {
                    return $this->storePurchaseResponse($existingPurchase, true);
                }
            }

            throw $e;
        } catch (\RuntimeException $e) {
            DB::rollBack();

            return response(['error' => $e->getMessage()], 400);
        }

        // ایجاد قسط‌ها در صورت اقساطی بودن
        if ($paymentType === 'installment' && $installmentCount && $installmentAmount) {
            $this->createInstallments($purchase, $installmentCount, $installmentAmount, $finalTotalAmount);
            
            // کسر اعتبار اقساطی: مبلغ باقیمانده (کل مبلغ منهای قسط اول که پرداخت شده - یک سوم)
            if ($phone) {
                $userShiksho = UserShiksho::where('phone', $phone)
                    ->when($purchaseAtelierId !== null, function ($q) use ($purchaseAtelierId) {
                        $q->where('atelier_id', $purchaseAtelierId);
                    })
                    ->first();
                if ($userShiksho) {
                    // مبلغ قسط اول که پرداخت شده است (یک سوم)
                    $firstInstallmentAmount = $this->roundToThreeZeroEnding($finalTotalAmount / 3);
                    // مبلغ باقیمانده که باید از اعتبار اقساطی کسر شود
                    $remainingAmount = $finalTotalAmount - $firstInstallmentAmount;
                    
                    if ($remainingAmount > 0 && $userShiksho->installment_credit >= $remainingAmount) {
                        $userShiksho->useInstallmentCredit($remainingAmount);
                    }
                }
            }
        }

        // اگر شماره تلفن وجود دارد
        if ($phone) {
            $enableLoyaltyCredit = \App\Models\Setting::isEnabled('enable_loyalty_credit', true);
            
            if ($enableLoyaltyCredit && $creditEarned > 0) {
                // به‌روزرسانی اعتبار (اعتبار قبلی صفر می‌شود و اعتبار جدید اضافه می‌شود)
                UserShiksho::updateCredit($phone, $creditEarned, $purchaseAtelierId);

                // ارسال پیامک بعد از ذخیره خرید (فقط اگر اعتبار کسب شده باشد)
                $creditFormatted = number_format($creditEarned, 0);
                $shopName = SmsTools::shopSmsBrand($purchaseAtelierId);
                $text = "{$shopName}\nهمراه عزیز مبلغ {$creditFormatted} تومان به اعتبار شما برای خرید بعدی اضافه شد";
                try {
                    SmsTools::sendShopSms($phone, $text, (string) $purchase->id, $creditEarned, 'credit', $purchaseAtelierId);
                } catch (\App\Exceptions\InsufficientShopSmsQuotaException) {
                    // خرید ثبت می‌شود؛ پیامک بدون اعتبار ارسال نمی‌شود
                }
            } else {
                // اگر اعتبار غیرفعال باشد یا اعتبار کسب نشده باشد (به دلیل تخفیف)، فقط پیام ساده بفرست
                $shopName = SmsTools::shopSmsBrand($purchaseAtelierId);
                $text = "{$shopName}\nبا تشکر از خرید شما";
                try {
                    SmsTools::sendShopSms($phone, $text, (string) $purchase->id, null, 'purchase', $purchaseAtelierId);
                } catch (\App\Exceptions\InsufficientShopSmsQuotaException) {
                    //
                }
            }

            // ثبت شماره تلفن در جدول customer_phones
            CustomerPhone::createNewPhone($phone);
        }

        return $this->storePurchaseResponse($purchase, false);
    }

    protected function normalizeClientId($clientId): ?string
    {
        if ($clientId === null) {
            return null;
        }

        $clientId = trim((string) $clientId);

        return $clientId !== '' ? $clientId : null;
    }

    protected function findPurchaseByClientId(?int $atelierId, string $clientId): ?Purchase
    {
        $query = Purchase::query()->where('client_id', $clientId);

        if ($atelierId !== null) {
            $query->where('atelier_id', $atelierId);
        } else {
            $query->whereNull('atelier_id');
        }

        return $query->first();
    }

    protected function isDuplicateClientIdException(QueryException $e): bool
    {
        $errorCode = (int) ($e->errorInfo[1] ?? 0);

        return $errorCode === 1062 || strpos(strtolower($e->getMessage()), 'duplicate') !== false;
    }

    protected function storePurchaseResponse(Purchase $purchase, bool $alreadyExists)
    {
        $purchase->load(['purchasedProducts.product', 'purchasedProducts.producedGood', 'purchasedProducts.rawMaterial']);
        if ($purchase->isInstallment()) {
            $purchase->load('installments');
        }
        if ($purchase->isDebt()) {
            $purchase->setAttribute('payable_amount', $purchase->payableAmount());
            $purchase->setAttribute('payment_type_label', 'قرضی');
        }
        if ($purchase->isCheque()) {
            $purchase->load('cheque');
            $purchase->setAttribute('payable_amount', $purchase->payableAmount());
            $purchase->setAttribute('is_cheque_settled', $purchase->isChequeSettled());
            $purchase->setAttribute('outstanding_cheque_amount', $purchase->outstandingChequeAmount());
            $purchase->setAttribute('cheque_amount', $purchase->chequeAmount());
            $purchase->setAttribute('immediate_paid_amount', $purchase->immediatePaidAmount());
            $purchase->setAttribute(
                'payment_type_label',
                $purchase->immediatePaidAmount() > 0.02 ? 'نقد/کارت + چک' : 'چکی'
            );
        }

        $payload = $purchase->toArray();
        $payload['id'] = $purchase->id;
        $payload['already_exists'] = $alreadyExists;

        if ($alreadyExists) {
            $payload['code'] = 'duplicate_client_id';
            $payload['message'] = 'این فاکتور قبلاً با همین client_id ثبت شده است.';
        }

        return response($payload, $alreadyExists ? 200 : 201);
    }

    public function show(Purchase $purchase)
    {
        $purchase->load(['purchasedProducts.product', 'purchasedProducts.producedGood', 'purchasedProducts.rawMaterial']);
        if ($purchase->isInstallment()) {
            $purchase->load('installments');
        }
        if ($purchase->isDebt()) {
            $purchase->setAttribute('payable_amount', $purchase->payableAmount());
            $purchase->setAttribute('payment_type_label', 'قرضی');
        }
        if ($purchase->isCheque()) {
            $purchase->load('cheque');
            $purchase->setAttribute('payable_amount', $purchase->payableAmount());
            $purchase->setAttribute('is_cheque_settled', $purchase->isChequeSettled());
            $purchase->setAttribute('outstanding_cheque_amount', $purchase->outstandingChequeAmount());
            $purchase->setAttribute('cheque_amount', $purchase->chequeAmount());
            $purchase->setAttribute('immediate_paid_amount', $purchase->immediatePaidAmount());
            $purchase->setAttribute(
                'payment_type_label',
                $purchase->immediatePaidAmount() > 0.02 ? 'نقد/کارت + چک' : 'چکی'
            );
        }
        return response($purchase, 200);
    }

    public function update(Request $request, Purchase $purchase)
    {
        $request->validate([
            'phone' => 'sometimes|nullable|string|digits:11',
            'total_amount' => 'sometimes|required|numeric|min:0',
            'credit_used' => 'sometimes|required|numeric|min:0',
            'credit_earned' => 'sometimes|required|numeric|min:0',
        ]);

        $purchase->update($request->only(['phone', 'total_amount', 'credit_used', 'credit_earned']));

        return response($purchase->load([
            'purchasedProducts.product',
            'purchasedProducts.producedGood',
            'purchasedProducts.rawMaterial',
        ]), 200);
    }

    public function destroy(Purchase $purchase)
    {
        $purchase->delete();
        return response(['message' => 'سبد خرید حذف شد'], 200);
    }

    /**
     * تسویهٔ پرداخت در لحظهٔ فروش: کارت / نقد دستی (جمع باید برابر مبلغ پرداختی باشد).
     *
     * @return array{card_amount: float, cash_amount: float}
     */
    private function resolvePurchaseSettlement(Request $request, float $amountPaidNow): array
    {
        $card = (float) $request->input('card_amount', 0);
        $cash = (float) $request->input('cash_amount', 0);
        $settlement = $request->input('payment_settlement');

        if ($card <= 0 && $cash <= 0 && $settlement === 'card') {
            $card = $amountPaidNow;
        } elseif ($card <= 0 && $cash <= 0 && $settlement === 'cash') {
            $cash = $amountPaidNow;
        } elseif ($card <= 0 && $cash <= 0) {
            $cash = $amountPaidNow;
        }

        if (abs(($card + $cash) - $amountPaidNow) > 0.02) {
            abort(response()->json([
                'message' => 'جمع مبلغ کارت و نقد باید برابر مبلغ پرداختی باشد.',
                'amount_paid' => $amountPaidNow,
                'card_amount' => $card,
                'cash_amount' => $cash,
            ], 422));
        }

        return [
            'card_amount' => round($card, 2),
            'cash_amount' => round($cash, 2),
        ];
    }

    private function roundToThreeZeroEnding($number)
    {
        return PriceTools::roundSalePrice((float) $number);
    }
     





    /**
     * ایجاد قسط‌ها برای خرید اقساطی
     * قسط اول: یک سوم مبلغ کل (نقد)
     * بقیه اقساط: تقسیم مبلغ باقیمانده با سود
     * 
     * @param Purchase $purchase
     * @param int $installmentCount تعداد کل اقساط
     * @param float $installmentAmount مبلغ هر قسط باقیمانده (بعد از قسط اول)
     * @param float $totalAmount مبلغ کل با سود
     */
    private function createInstallments(Purchase $purchase, int $installmentCount, float $installmentAmount, float $totalAmount)
    {
        $installments = [];
        $today = Jalalian::now();
        $baseDate = $today->toCarbon();
        
        // یک سوم مبلغ کل (قسط اول - نقد)
        $firstInstallmentAmount = $this->roundToThreeZeroEnding($totalAmount / 3);
        
        // تعداد ماه‌های باقیمانده
        $remainingMonths = $installmentCount - 1;
        
        // مبلغ کل باقیمانده با سود
        $remainingAmountWithInterest = $installmentAmount * $remainingMonths;
        
        // محاسبه تفاوت برای اضافه کردن به آخرین قسط
        $expectedTotal = $firstInstallmentAmount + $remainingAmountWithInterest;
        $difference = $totalAmount - $expectedTotal;
        
        for ($i = 1; $i <= $installmentCount; $i++) {
            if ($i === 1) {
                // قسط اول: یک سوم نقد
                $dueDate = $baseDate->toDateString();
                $isPaid = true;
                $paidAt = now();
                $amount = $firstInstallmentAmount;
            } else {
                // بقیه اقساط
                $dueDate = $baseDate->copy()->addMonths($i - 1)->toDateString();
                $isPaid = false;
                $paidAt = null;
                
                $amount = $installmentAmount;
                // اگر آخرین قسط است و تفاوتی وجود دارد، اضافه کن
                if ($i === $installmentCount && abs($difference) > 0.01) {
                    $amount += $difference;
                }
            }
            
            // رند کردن مبلغ قسط به عددی که سه رقم آخرش 0 باشد
            $amount = $this->roundToThreeZeroEnding($amount);
            
            $installments[] = [
                'purchase_id' => $purchase->id,
                'installment_number' => $i,
                'amount' => $amount,
                'due_date' => $dueDate,
                'is_paid' => $isPaid,
                'paid_at' => $paidAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        
        Installment::insert($installments);
    }

    /**
     * برگشت یک محصول از یک خرید
     * محصول از لیست خرید حذف شده و موجودی برگردانده می‌شود
     * 
     * @param Purchase $purchase
     * @param PurchasedProduct $purchasedProduct
     * @return \Illuminate\Http\Response
     */
    public function returnItem(Request $request, Purchase $purchase, PurchasedProduct $purchasedProduct)
    {
        $request->validate([
            'quantity' => 'sometimes|numeric|min:0.001',
            'notes' => 'nullable|string|max:2000',
            'phone' => 'nullable|string|max:20',
        ]);

        $product = $purchasedProduct->product;
        $unitType = $product instanceof Product
            ? ($product->unit_type ?? Product::UNIT_PIECE)
            : Product::UNIT_KG;
        if (! $product && ! $purchasedProduct->produced_good_id && ! $purchasedProduct->raw_material_id) {
            return response(['error' => 'محصول یافت نشد'], 404);
        }
        $lineQuantity = ProductQuantityTools::normalize($purchasedProduct->quantity, $unitType);
        $returnQty = $request->has('quantity')
            ? ProductQuantityTools::normalize($request->input('quantity'), $unitType)
            : $lineQuantity;

        if ($error = ProductQuantityTools::validateReturnQuantity($returnQty, $lineQuantity, $unitType)) {
            return response(['error' => $error], 422);
        }

        $userName = null;
        $staffAtelierId = $this->staffShopAtelierId($request);
        if ($staffAtelierId !== null) {
            $user = $this->requireStaffShopUser($request);
            $userName = trim($user->name.' '.$user->last_name);
            if ($purchase->atelier_id !== null && (int) $purchase->atelier_id !== (int) $staffAtelierId) {
                return response(['error' => 'این فاکتور متعلق به فروشگاه شما نیست'], 403);
            }
        }

        try {
            $result = \App\Services\PurchaseItemReturnService::processReturn(
                $purchase,
                $purchasedProduct,
                $returnQty,
                $userName,
                $request->input('notes'),
                $request->input('phone')
            );
        } catch (\InvalidArgumentException $e) {
            return response(['error' => $e->getMessage(), 'message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'محصول با موفقیت برگشت داده شد. مبلغ به اعتبار مشتری اضافه شد.',
            'returned_item' => $result['returned_item'],
            'row' => $result['row'],
            'phone' => $result['phone'] ?? $purchase->phone,
            'customer_credit' => $result['customer_credit'] ?? null,
            'purchase' => $result['purchase'] ?? $purchase,
        ], 200);
    }

    /**
     * برگشت کامل یک خرید (همه اقلام باقی‌مانده).
     * POST /api/purchased-products/{purchase}/return
     */
    public function returnPurchase(Request $request, Purchase $purchase)
    {
        $request->validate([
            'notes' => 'nullable|string|max:2000',
            'phone' => 'nullable|string|max:20',
        ]);

        $userName = null;
        $staffAtelierId = $this->staffShopAtelierId($request);
        if ($staffAtelierId !== null) {
            $user = $this->requireStaffShopUser($request);
            $userName = trim($user->name.' '.$user->last_name);
            if ($purchase->atelier_id !== null && (int) $purchase->atelier_id !== (int) $staffAtelierId) {
                return response(['error' => 'این فاکتور متعلق به فروشگاه شما نیست'], 403);
            }
        }

        try {
            $result = \App\Services\PurchaseItemReturnService::processFullReturn(
                $purchase,
                $request->input('phone'),
                $userName,
                $request->input('notes')
            );
        } catch (\InvalidArgumentException $e) {
            return response(['error' => $e->getMessage(), 'message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'خرید به‌طور کامل برگشت داده شد. مبلغ به اعتبار مشتری اضافه شد.',
            'full_return' => true,
            'returned_items' => $result['returned_items'],
            'rows' => $result['rows'],
            'credit_refunded' => $result['credit_refunded'],
            'credit_earned_reversed' => $result['credit_earned_reversed'],
            'phone' => $result['phone'],
            'customer_credit' => $result['customer_credit'],
            'purchase' => $result['purchase'],
        ], 200);
    }

    /**
     * دریافت اعتبار کاربر بر اساس شماره تلفن
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function getCreditByPhone(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        \App\Models\Setting::setShopContext($atelierId);

        $request->validate([
            'phone' => 'required|string|digits:11',
        ]);

        $phone = $request->input('phone');
        $userShiksho = UserShiksho::where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->first();

        $credit = $userShiksho ? $userShiksho->credit : 0;

        return response([
            'phone' => $phone,
            'use_credit' => $credit,
            'credit' => $credit,
        ], 200);
    }

    /**
     * دریافت اعتبار و سقف خرید اقساطی کاربر
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function getInstallmentCredit(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        \App\Models\Setting::setShopContext($atelierId);

        $request->validate([
            'phone' => 'required|string|digits:11',
        ]);

        $phone = $request->input('phone');
        $userShiksho = UserShiksho::where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->first();

        $installmentCredit = $userShiksho ? (float) $userShiksho->installment_credit : 0;
        
        // سقف خرید اقساطی همان اعتبار اقساطی کاربر است
        $installmentLimit = $installmentCredit;

        return response([
            'phone' => $phone,
            'installment_credit' => $installmentCredit,
            'installment_limit' => $installmentLimit,
            'can_buy_installment' => $installmentCredit > 0,
        ], 200);
    }

    /**
     * محاسبه مبلغ اقساط بر اساس مبلغ خرید و تعداد ماه‌ها
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function calculateInstallments(Request $request)
    {
        $atelierId = $this->resolveAtelierIdForCalculateInstallments($request);
        \App\Models\Setting::setShopContext($atelierId);

        $validated = $request->validate([
            'total_amount' => 'required|numeric|min:0',
            'installment_count' => 'required|integer|min:2|max:24',
            'phone' => 'nullable|string|digits:11',
        ]);

        $totalAmount = (float) $validated['total_amount'];
        $installmentCount = (int) $validated['installment_count'];
        $phone = $validated['phone'] ?? null;

        $monthlyInterestRate = (float) \App\Models\Setting::get('installment_monthly_interest_rate', 0);
        $installmentAmount = 0.0;
        
        // یک سوم مبلغ به صورت نقد (قسط اول)
        $firstInstallmentAmount = $this->roundToThreeZeroEnding($totalAmount / 3);
        
        // بقیه مبلغ (دو سوم)
        $remainingAmount = $totalAmount - $firstInstallmentAmount;
        
        // تعداد ماه‌های باقیمانده (بعد از قسط اول)
        $remainingMonths = $installmentCount - 1;
        
        $finalTotalAmount = $totalAmount;
        $totalInterest = 0;
        $installmentDetails = [];

        if ($monthlyInterestRate > 0 && $remainingMonths > 0) {
            // محاسبه سود: مبلغ باقیمانده × نرخ سود × تعداد ماه‌های باقیمانده
            $totalInterest = $remainingAmount * ($monthlyInterestRate / 100) * $remainingMonths;
            
            // مبلغ کل با سود = مبلغ باقیمانده + سود
            $remainingAmountWithInterest = $remainingAmount + $totalInterest;
            
            // مبلغ کل نهایی = قسط اول + بقیه با سود
            $finalTotalAmount = $this->roundToThreeZeroEnding($firstInstallmentAmount + $remainingAmountWithInterest);
            
            // مبلغ هر قسط باقیمانده
            $installmentAmount = $this->roundToThreeZeroEnding($remainingAmountWithInterest / $remainingMonths);
            
            // جزئیات قسط اول
            $installmentDetails[] = [
                'month' => 1,
                'remaining_amount' => $totalAmount,
                'interest' => 0,
                'base_payment' => $firstInstallmentAmount,
                'payment_type' => 'cash',
            ];
            
            // جزئیات بقیه اقساط
            for ($month = 2; $month <= $installmentCount; $month++) {
                $installmentDetails[] = [
                    'month' => $month,
                    'remaining_amount' => $this->roundToThreeZeroEnding($remainingAmountWithInterest - ($installmentAmount * ($month - 2))),
                    'interest' => $this->roundToThreeZeroEnding($totalInterest / $remainingMonths),
                    'base_payment' => $installmentAmount,
                    'payment_type' => 'installment',
                ];
            }
        } else {
            // بدون سود
            $finalTotalAmount = $this->roundToThreeZeroEnding($totalAmount);
            
            // مبلغ هر قسط باقیمانده
            $installmentAmount = $this->roundToThreeZeroEnding($remainingAmount / $remainingMonths);
            
            // جزئیات قسط اول
            $installmentDetails[] = [
                'month' => 1,
                'remaining_amount' => $totalAmount,
                'interest' => 0,
                'base_payment' => $firstInstallmentAmount,
                'payment_type' => 'cash',
            ];
            
            // جزئیات بقیه اقساط
            for ($month = 2; $month <= $installmentCount; $month++) {
                $installmentDetails[] = [
                    'month' => $month,
                    'remaining_amount' => $this->roundToThreeZeroEnding($remainingAmount - ($installmentAmount * ($month - 2))),
                    'interest' => 0,
                    'base_payment' => $installmentAmount,
                    'payment_type' => 'installment',
                ];
            }
        }

        // چک اعتبار اقساطی در صورت وجود شماره تلفن
        $userInstallmentCredit = null;
        $hasEnoughCredit = null;
        $creditShortage = null;
        
        if ($phone) {
            $userShiksho = UserShiksho::where('phone', $phone)
                ->where('atelier_id', $atelierId)
                ->first();
            $userInstallmentCredit = $userShiksho ? (float) $userShiksho->installment_credit : 0;
            
            // اعتبار اقساطی باید به اندازه کل مبلغ با سود باشد
            $hasEnoughCredit = $userInstallmentCredit >= $finalTotalAmount;
            
            if (!$hasEnoughCredit) {
                $creditShortage = $this->roundToThreeZeroEnding($finalTotalAmount - $userInstallmentCredit);
            }
        }

        $response = [
            'atelier_id' => $atelierId,
            'total_amount' => $this->roundToThreeZeroEnding($totalAmount),
            'installment_count' => $installmentCount,
            'monthly_interest_rate' => $monthlyInterestRate,
            'total_interest' => $this->roundToThreeZeroEnding($totalInterest),
            'final_total_amount' => $finalTotalAmount,
            'installment_amount' => $this->roundToThreeZeroEnding($installmentAmount),
            'installment_details' => $installmentDetails,
        ];

        // اضافه کردن اطلاعات اعتبار اقساطی در صورت وجود شماره تلفن
        if ($phone !== null) {
            $response['phone'] = $phone;
            $response['user_installment_credit'] = $this->roundToThreeZeroEnding($userInstallmentCredit);
            $response['has_enough_credit'] = $hasEnoughCredit;
            
            if (!$hasEnoughCredit) {
                $response['credit_shortage'] = $creditShortage;
                $response['error'] = 'اعتبار اقساطی کاربر کافی نیست. اعتبار مورد نیاز: ' . number_format($finalTotalAmount, 0) . ' تومان، اعتبار موجود: ' . number_format($userInstallmentCredit, 0) . ' تومان';
            }
        }

        if ($phone && $hasEnoughCredit === false) {
            return response()->json($response, 400);
        }

        return response()->json($response, 200);
    }

    /**
     * فروشگاه برای محاسبه اقساط: از کاربر لاگین، یا atelier_id در body (اگر توکن در هدر نرسد).
     */
    private function resolveAtelierIdForCalculateInstallments(Request $request): int
    {
        $requestedAtelierId = $this->parseRequestedAtelierId($request);
        $actor = $this->shopRequestActor($request);

        if ($actor instanceof \App\Models\User) {
            $freshAtelierId = \App\Models\User::where('id', $actor->id)->value('atelier_id');
            if ($freshAtelierId) {
                return (int) $freshAtelierId;
            }
            if ($requestedAtelierId !== null && \App\Models\Atelier::where('id', $requestedAtelierId)->exists()) {
                return $requestedAtelierId;
            }
        }

        if ($requestedAtelierId !== null && \App\Models\Atelier::where('id', $requestedAtelierId)->exists()) {
            return $requestedAtelierId;
        }

        return $this->shopAtelierIdOrAbort($request);
    }
}
