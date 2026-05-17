<?php

namespace App\Http\Controllers;

use App\Models\Manufacturer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ManufacturerController extends Controller
{
    /**
     * نمایش لیست تولیدکنندگان
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $query = Manufacturer::query()
            ->where('atelier_id', $atelierId)
            ->withCount(['products' => function ($q) use ($atelierId) {
                $q->where('atelier_id', $atelierId);
            }])
            ->addSelect([
                'total_sold_quantity' => DB::table('products')
                    ->join('purchased_products', 'products.id', '=', 'purchased_products.product_id')
                    ->join('purchases', 'purchased_products.purchase_id', '=', 'purchases.id')
                    ->whereColumn('products.manufacturer_id', 'manufacturers.id')
                    ->where('products.atelier_id', $atelierId)
                    ->where('purchases.atelier_id', $atelierId)
                    ->select(DB::raw('COALESCE(SUM(purchased_products.quantity), 0)')),
            ])
            ->orderBy('name', 'asc');

    // جستجو
    $searchDataModel = json_decode($request->input('searchFilterModel'));
    if ($searchDataModel) {
        $query->where(function ($q) use ($searchDataModel) {
            if (is_object($searchDataModel)) {
                if (isset($searchDataModel->name)) {
                    $q->where('name', 'like', '%' . $searchDataModel->name . '%');
                }
            } elseif (is_string($searchDataModel)) {
                $q->where('name', 'like', '%' . $searchDataModel . '%');
            }
        });
    }

    // فقط اگر paginate=true بود صفحه‌بندی کن
    if ($request->boolean('paginate')) {
        $perPage = $request->input('per_page', 20);

        $manufacturers = $query->paginate($perPage);
        $manufacturers->withPath(url()->current());

        $manufacturers->getCollection()->transform(function ($manufacturer) {
            $manufacturer->products_count = $manufacturer->products_count ?? 0;
            $manufacturer->total_sold_quantity = (int) ($manufacturer->total_sold_quantity ?? 0);
            return $manufacturer;
        });

        return response($manufacturers, 200);
    }

    // حالت پیش‌فرض: همه
    $manufacturers = $query->get();

    $manufacturers->transform(function ($manufacturer) {
        $manufacturer->products_count = $manufacturer->products_count ?? 0;
        $manufacturer->total_sold_quantity = (int) ($manufacturer->total_sold_quantity ?? 0);
        return $manufacturer;
    });

    return response($manufacturers, 200);
}

    /**
     * افزودن تولیدکننده جدید
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        $nameUnique = Rule::unique('manufacturers', 'name');
        if ($atelierId !== null) {
            $nameUnique = $nameUnique->where('atelier_id', $atelierId);
        } else {
            $nameUnique = $nameUnique->whereNull('atelier_id');
        }

        $fields = $request->validate([
            'name' => ['required', 'string', 'max:255', $nameUnique],
        ]);

        if ($atelierId !== null) {
            $fields['atelier_id'] = $atelierId;
        }

        $manufacturer = Manufacturer::create($fields);
        return response($manufacturer, 201);
    }

    /**
     * نمایش جزئیات یک تولیدکننده
     */
    public function show(Request $request, Manufacturer $manufacturer)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ((int) $manufacturer->atelier_id !== $atelierId) {
            return response(['message' => 'یافت نشد'], 404);
        }

        $manufacturer->loadCount(['products' => function ($q) use ($atelierId) {
            $q->where('atelier_id', $atelierId);
        }]);
        $manufacturer->products_count = $manufacturer->products_count ?? 0;

        // محاسبه تعداد فروش
        $totalSoldQuantity = DB::table('products')
            ->join('purchased_products', 'products.id', '=', 'purchased_products.product_id')
            ->join('purchases', 'purchased_products.purchase_id', '=', 'purchases.id')
            ->where('products.manufacturer_id', $manufacturer->id)
            ->where('products.atelier_id', $atelierId)
            ->where('purchases.atelier_id', $atelierId)
            ->sum('purchased_products.quantity');

        $manufacturer->total_sold_quantity = (int) ($totalSoldQuantity ?? 0);

        return response($manufacturer, 200);
    }

    /**
     * ویرایش تولیدکننده
     */
    public function update(Request $request, Manufacturer $manufacturer)
    {
        $this->assertModelBelongsToStaffAtelier($request, $manufacturer);

        $atelierId = $this->staffShopAtelierId($request);
        $nameUnique = Rule::unique('manufacturers', 'name')->ignore($manufacturer->id);
        if ($atelierId !== null) {
            $nameUnique = $nameUnique->where('atelier_id', $atelierId);
        } else {
            $nameUnique = $nameUnique->whereNull('atelier_id');
        }

        $fields = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', $nameUnique],
        ]);

        $manufacturer->update($fields);
        return response($manufacturer, 200);
    }

    /**
     * حذف تولیدکننده
     */
    public function destroy(Request $request, Manufacturer $manufacturer)
    {
        $this->assertModelBelongsToStaffAtelier($request, $manufacturer);
        $manufacturer->delete();
        return response(['message' => 'تولیدکننده با موفقیت حذف شد'], 200);
    }

    /**
     * گزارش فروش بر اساس تولیدکننده
     * نمایش تولیدکنندگان با بیشترین فروش
     */
    public function salesReport(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        // فیلتر تاریخ (اختیاری)
        $dateFilter = $request->input('filter');
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // Query برای محاسبه فروش هر تولیدکننده
        $query = DB::table('manufacturers')
            ->where('manufacturers.atelier_id', $atelierId)
            ->leftJoin('products', function ($join) use ($atelierId) {
                $join->on('manufacturers.id', '=', 'products.manufacturer_id')
                    ->where('products.atelier_id', '=', $atelierId);
            })
            ->leftJoin('purchased_products', 'products.id', '=', 'purchased_products.product_id')
            ->leftJoin('purchases', function ($join) use ($atelierId) {
                $join->on('purchased_products.purchase_id', '=', 'purchases.id')
                    ->where('purchases.atelier_id', '=', $atelierId);
            })
            ->select(
                'manufacturers.id',
                'manufacturers.name',
                DB::raw('COALESCE(SUM(purchased_products.quantity), 0) as total_quantity_sold'),
                DB::raw('COALESCE(SUM(purchased_products.quantity * purchased_products.sale_price), 0) as total_sales_amount'),
                DB::raw('COUNT(DISTINCT purchases.id) as total_orders')
            )
            ->groupBy('manufacturers.id', 'manufacturers.name');

        // اعمال فیلتر تاریخ
        if ($dateFilter || $fromDate || $toDate) {
            $query->whereNotNull('purchases.created_at');
            
            if ($dateFilter === 'today') {
                $query->whereDate('purchases.created_at', \Carbon\Carbon::today());
            } elseif ($dateFilter === 'week') {
                $now = \Morilog\Jalali\Jalalian::now();
                $dayOfWeek = $now->getDayOfWeek();
                $startOfWeek = \Morilog\Jalali\Jalalian::now()->subDays($dayOfWeek)->toCarbon()->startOfDay();
                $endOfWeek = \Morilog\Jalali\Jalalian::now()->addDays(6 - $dayOfWeek)->toCarbon()->endOfDay();
                $query->whereBetween('purchases.created_at', [$startOfWeek, $endOfWeek]);
            } elseif ($dateFilter === 'month') {
                $now = \Morilog\Jalali\Jalalian::now();
                $year = $now->getYear();
                $month = $now->getMonth();
                $startOfMonth = (new \Morilog\Jalali\Jalalian($year, $month, 1))->toCarbon()->startOfDay();
                $endOfMonth = (new \Morilog\Jalali\Jalalian($year, $month, 1))->addMonths(1)->subDays(1)->toCarbon()->endOfDay();
                $query->whereBetween('purchases.created_at', [$startOfMonth, $endOfMonth]);
            } elseif ($dateFilter === 'year') {
                $now = \Morilog\Jalali\Jalalian::now();
                $year = $now->getYear();
                $startOfYear = (new \Morilog\Jalali\Jalalian($year, 1, 1))->toCarbon()->startOfDay();
                $endOfYear = (new \Morilog\Jalali\Jalalian($year, 12, 29))->toCarbon()->endOfDay();
                $query->whereBetween('purchases.created_at', [$startOfYear, $endOfYear]);
            } elseif ($dateFilter === 'range') {
                if ($fromDate) {
                    $fromDateObj = json_decode($fromDate);
                    $fromCarbon = (new \Morilog\Jalali\Jalalian($fromDateObj->year, $fromDateObj->month, $fromDateObj->day))->toCarbon()->startOfDay();
                    $query->where('purchases.created_at', '>=', $fromCarbon);
                }
                if ($toDate) {
                    $toDateObj = json_decode($toDate);
                    $toCarbon = (new \Morilog\Jalali\Jalalian($toDateObj->year, $toDateObj->month, $toDateObj->day))->toCarbon()->endOfDay();
                    $query->where('purchases.created_at', '<=', $toCarbon);
                }
            }
        }

        // مرتب‌سازی بر اساس بیشترین فروش
        $orderBy = $request->input('order_by', 'total_sales_amount'); // total_sales_amount یا total_quantity_sold
        $orderDirection = $request->input('order_direction', 'desc');
        
        if ($orderBy === 'quantity') {
            $query->orderBy('total_quantity_sold', $orderDirection);
        } else {
            $query->orderBy('total_sales_amount', $orderDirection);
        }

        $results = $query->get();

        // محاسبه جمع کل
        $totalSalesAmount = $results->sum('total_sales_amount');
        $totalQuantitySold = $results->sum('total_quantity_sold');
        $totalOrders = $results->sum('total_orders');

        return response([
            'data' => $results,
            'summary' => [
                'total_manufacturers' => $results->count(),
                'total_sales_amount' => (float) $totalSalesAmount,
                'total_quantity_sold' => (int) $totalQuantitySold,
                'total_orders' => (int) $totalOrders,
            ]
        ], 200);
    }
}

