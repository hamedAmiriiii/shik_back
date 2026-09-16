<?php

namespace App\Http\Controllers;

use App\Models\CustomerPhone;
use App\Models\Purchase;
use App\Models\UserShiksho;
use App\Services\ShopBeneficiaryService;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerController extends Controller
{
    /**
     * ثبت کاربر در جدول user_shiksho
     */
    public function registerUserShiksho(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string|digits:11',
            'name' => 'nullable|string|max:255',
            'full_name' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date',
        ]);

        $atelierId = $this->shopAtelierIdOrAbort($request);
        $name = trim((string) ($validated['name'] ?? $validated['full_name'] ?? ''));
        $name = $name !== '' ? $name : null;
        $birthDate = $validated['birth_date'] ?? null;

        $create = [
            'credit' => 0,
            'installment_credit' => 0,
            'credit_last_updated_at' => now(),
            'last_warning_sent_at' => null,
        ];
        if ($name !== null && Schema::hasColumn('user_shiksho', 'name')) {
            $create['name'] = $name;
        }
        if ($birthDate !== null && Schema::hasColumn('user_shiksho', 'birth_date')) {
            $create['birth_date'] = $birthDate;
        }

        $userShiksho = UserShiksho::firstOrCreate(
            ['phone' => $validated['phone'], 'atelier_id' => $atelierId],
            $create
        );

        if (! $userShiksho->wasRecentlyCreated) {
            $updates = [];
            if ($name !== null && Schema::hasColumn('user_shiksho', 'name')) {
                $updates['name'] = $name;
            }
            if ($birthDate !== null && Schema::hasColumn('user_shiksho', 'birth_date')) {
                $updates['birth_date'] = $birthDate;
            }
            if ($updates !== []) {
                $userShiksho->update($updates);
            }
        }

        $smsSent = false;
        $smsError = null;
        $shopBrand = SmsTools::shopSmsBrand($atelierId);
        if ($userShiksho->wasRecentlyCreated) {
            $welcomeMessage = "به باشگاه مشتریان {$shopBrand} خوش آمدید";
            try {
                SmsTools::sendShopSms(
                    $validated['phone'],
                    $welcomeMessage,
                    null,
                    null,
                    'customer_register',
                    $atelierId
                );
                $smsSent = true;
            } catch (\App\Exceptions\InsufficientShopSmsQuotaException $e) {
                $smsSent = false;
                $smsError = $e->getMessage();
            } catch (\Exception $e) {
                $smsSent = false;
                $smsError = $e->getMessage();
            }
        }


        return response([
            'message' => $userShiksho->wasRecentlyCreated
                ? "کاربر با موفقیت در باشگاه مشتریان {$shopBrand} ثبت شد"
                : "کاربر قبلاً در باشگاه مشتریان {$shopBrand} ثبت شده است",
            'already_exists' => !$userShiksho->wasRecentlyCreated,
            'sms_sent' => $smsSent,
            'sms_error' => $smsError,
            'data' => $userShiksho
        ], $userShiksho->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * لیست  خریداران از فروشگاه (آنهایی که شماره تلفنشان ثبت شده)
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        
        // دریافت لیست خریداران با اطلاعات آماری
        $query = DB::table('purchases')
            ->select(
                'purchases.phone',
                DB::raw('COUNT(purchases.id) as total_purchases'),
                DB::raw('SUM(purchases.total_amount) as total_spent'),
                DB::raw('SUM(purchases.credit_earned) as total_credit_earned'),
                DB::raw('MAX(purchases.created_at) as last_purchase_date')
            )
            ->where('purchases.atelier_id', $atelierId)
            ->whereNotNull('purchases.phone')
            ->where('purchases.phone', '!=', '')
            ->groupBy('purchases.phone');

        if (Schema::hasTable('user_shiksho') && Schema::hasColumn('user_shiksho', 'name')) {
            $query->leftJoin('user_shiksho', function ($join) use ($atelierId) {
                $join->on('user_shiksho.phone', '=', 'purchases.phone')
                    ->where('user_shiksho.atelier_id', '=', $atelierId);
            })->addSelect(DB::raw('MAX(user_shiksho.name) as name'));
        }

        // اعمال جستجو
        if ($searchDataModel) {
            if (is_object($searchDataModel)) {
                if (isset($searchDataModel->phone)) {
                    $query->where('purchases.phone', 'like', '%'.$searchDataModel->phone.'%');
                }
                if (isset($searchDataModel->name) && Schema::hasColumn('user_shiksho', 'name')) {
                    $query->where('user_shiksho.name', 'like', '%'.$searchDataModel->name.'%');
                }
            } elseif (is_string($searchDataModel)) {
                $query->where(function ($q) use ($searchDataModel) {
                    $q->where('purchases.phone', 'like', '%'.$searchDataModel.'%');
                    if (Schema::hasColumn('user_shiksho', 'name')) {
                        $q->orWhere('user_shiksho.name', 'like', '%'.$searchDataModel.'%');
                    }
                });
            }
        }

        // دریافت اعتبار فعلی هر مشتری
        $customers = $query->orderBy('last_purchase_date', 'desc')
            ->paginate($request->input('per_page', 50));

        // اضافه کردن اعتبار فعلی به هر مشتری (همان فروشگاه)
        foreach ($customers->items() as $customer) {
            $userShiksho = UserShiksho::where('phone', $customer->phone)
                ->where('atelier_id', $atelierId)
                ->first();
            $customer->current_credit = $userShiksho ? $userShiksho->credit : 0;
            if (! isset($customer->name) || $customer->name === null) {
                $customer->name = $userShiksho->name ?? null;
            }
        }

        return response($customers, 200);
    }

    /**
     * جزئیات یک مشتری خاص بر اساس شماره تلفن
     */
    public function show(Request $request, $phone)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        // اطلاعات خریدهای مشتری
        $purchases = Purchase::where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->with('purchasedProducts.product')
            ->orderBy('id', 'desc')
            ->get();

        // اطلاعات اعتبار
        $userShiksho = UserShiksho::where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->first();

        // آمار کلی
        $stats = [
            'phone' => $phone,
            'name' => $userShiksho->name ?? null,
            'birth_date' => $userShiksho && $userShiksho->birth_date
                ? $userShiksho->birth_date->format('Y-m-d')
                : null,
            'id' => $userShiksho->id ?? null,
            'total_purchases' => $purchases->count(),
            'total_spent' => $purchases->sum('total_amount'),
            'total_credit_earned' => $purchases->sum('credit_earned'),
            'current_credit' => $userShiksho ? $userShiksho->credit : 0,
            'last_purchase_date' => $purchases->first() ? $purchases->first()->created_at : null,
        ];

        $asBeneficiary = $userShiksho ? ShopBeneficiaryService::totalsFor($userShiksho) : [
            'purchased_total' => 0,
            'paid_total' => 0,
            'unpaid_total' => 0,
            'invoice_total' => 0,
            'expense_total' => 0,
            'unpaid_invoice_total' => 0,
            'unpaid_expense_total' => 0,
            'invoice_count' => 0,
            'expense_count' => 0,
            'unpaid_invoice_count' => 0,
            'unpaid_expense_count' => 0,
        ];

        return response([
            'stats' => $stats,
            'purchases' => $purchases,
            'as_beneficiary' => $asBeneficiary,
        ], 200);
    }

    /**
     * داشبورد باشگاه مشتریان: پرفروش‌ها + آمار خرید ۳۰ روز
     */
    public function clubDashboard(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $since = now()->subDays(30);

        $inactive30d = (int) DB::table('purchases')
            ->select('phone')
            ->where('atelier_id', $atelierId)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->groupBy('phone')
            ->havingRaw('MAX(created_at) < ?', [$since])
            ->get()
            ->count();

        $frequent30d = (int) DB::table('purchases')
            ->select('phone')
            ->where('atelier_id', $atelierId)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->where('created_at', '>=', $since)
            ->groupBy('phone')
            ->havingRaw('COUNT(id) > 3')
            ->get()
            ->count();

        $clubMembers = Schema::hasTable('user_shiksho')
            ? (int) UserShiksho::where('atelier_id', $atelierId)->count()
            : 0;

        $salesQuery = DB::table('purchased_products')
            ->join('purchases', 'purchased_products.purchase_id', '=', 'purchases.id')
            ->join('products', 'purchased_products.product_id', '=', 'products.id')
            ->where('products.atelier_id', $atelierId)
            ->where(function ($q) use ($atelierId) {
                $q->where('purchases.atelier_id', $atelierId)
                    ->orWhereNull('purchases.atelier_id');
            });

        $productIds = (clone $salesQuery)
            ->select('purchased_products.product_id', DB::raw('SUM(purchased_products.quantity) as total_sold'))
            ->groupBy('purchased_products.product_id')
            ->orderByDesc('total_sold')
            ->limit(4)
            ->pluck('product_id')
            ->toArray();

        $bestSelling = [];
        if (! empty($productIds)) {
            $totalSoldMap = (clone $salesQuery)
                ->select('purchased_products.product_id', DB::raw('SUM(purchased_products.quantity) as total_sold'))
                ->whereIn('purchased_products.product_id', $productIds)
                ->groupBy('purchased_products.product_id')
                ->pluck('total_sold', 'product_id')
                ->toArray();

            $products = \App\Models\Product::whereIn('id', $productIds)
                ->where('atelier_id', $atelierId)
                ->with(['images'])
                ->get()
                ->keyBy('id');

            foreach ($productIds as $productId) {
                $product = $products->get($productId);
                if (! $product) {
                    continue;
                }
                $image = null;
                if ($product->relationLoaded('images') && $product->images->isNotEmpty()) {
                    $first = $product->images->first();
                    $image = $first->url ?? $first->path ?? $first->image ?? null;
                }
                $bestSelling[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sale_price' => (float) $product->sale_price,
                    'quantity' => (float) $product->quantity,
                    'total_sold' => (int) ($totalSoldMap[$productId] ?? 0),
                    'image' => $image,
                ];
            }
        }

        return response([
            'stats' => [
                'inactive_30d' => $inactive30d,
                'frequent_30d' => $frequent30d,
                'club_members' => $clubMembers,
            ],
            'best_selling' => $bestSelling,
        ], 200);
    }

    /**
     * دریافت لیست مشتریان برای انتخاب (برای ارسال پیام)
     */
    public function getCustomersForBroadcast(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $select = [
            'user_shiksho.phone',
            DB::raw('COALESCE(pc.total_purchases, 0) as total_purchases'),
            DB::raw('COALESCE(pc.total_spent, 0) as total_spent'),
        ];
        if (Schema::hasColumn('user_shiksho', 'name')) {
            $select[] = 'user_shiksho.name';
        }

        $purchaseCounts = DB::table('purchases')
            ->select(
                'phone',
                DB::raw('COUNT(id) as total_purchases'),
                DB::raw('COALESCE(SUM(total_amount), 0) as total_spent')
            )
            ->where('atelier_id', $atelierId)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->groupBy('phone');

        $customers = DB::table('user_shiksho')
            ->leftJoinSub($purchaseCounts, 'pc', function ($join) {
                $join->on('pc.phone', '=', 'user_shiksho.phone');
            })
            ->select($select)
            ->where('user_shiksho.atelier_id', $atelierId)
            ->get()
            ->map(function ($item) {
                return [
                    'phone' => $item->phone,
                    'name' => $item->name ?? null,
                    'total_purchases' => (int) ($item->total_purchases ?? 0),
                    'total_spent' => (float) ($item->total_spent ?? 0),
                ];
            })
            ->values();

        return response([
            'customers' => $customers
        ], 200);
    }

    /**
     * ارسال پیام مشترک به مشتریان انتخاب شده
     */
    public function broadcastMessage(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
            'phones' => 'required|array|min:1',
            'phones.*' => 'required|string|digits:11',
        ]);

        // دریافت لیست شماره تلفن‌های انتخاب شده
        $phones = $request->input('phones');

        if (empty($phones)) {
            return response([
                'error' => 'هیچ شماره تلفنی انتخاب نشده است'
            ], 400);
        }

        $atelierId = $this->shopAtelierIdOrAbort($request);
        $fullMessage = SmsTools::shopSmsBrand($atelierId)."\n".$request->input('message');

        $partsEach = \App\Services\ShopSmsQuotaService::countSmsParts($fullMessage);
        $totalParts = $partsEach * count($phones);
        $balance = \App\Services\ShopSmsQuotaService::getBalance($atelierId);
        if ($balance < $totalParts) {
            throw new \App\Exceptions\InsufficientShopSmsQuotaException($totalParts, $balance);
        }

        $successCount = 0;
        $failedCount = 0;
        $results = [];

        foreach ($phones as $phone) {
            try {
                $result = SmsTools::sendShopSms(
                    $phone,
                    $fullMessage,
                    null,
                    null,
                    'broadcast',
                    $atelierId
                );
                $successCount++;
                $results[] = [
                    'phone' => $phone,
                    'status' => 'success',
                    'result' => $result
                ];
            } catch (\App\Exceptions\InsufficientShopSmsQuotaException $e) {
                throw $e;
            } catch (\Exception $e) {
                $failedCount++;
                $results[] = [
                    'phone' => $phone,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        $response = [
            'message' => 'پیام به مشتریان ارسال شد',
            'total_customers' => count($phones),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
        ];

        // فقط در صورت وجود خطا، جزئیات خطاها را برگردان
        if ($failedCount > 0 && $failedCount <= 10) {
            $response['failed_results'] = array_filter($results, function($item) {
                return $item['status'] === 'failed';
            });
        }

        return response($response, 200);
    }
}

