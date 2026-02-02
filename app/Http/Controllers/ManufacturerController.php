<?php

namespace App\Http\Controllers;

use App\Models\Manufacturer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManufacturerController extends Controller
{
    /**
     * نمایش لیست تولیدکنندگان
     */
    public function index(Request $request)
    {
        $query = Manufacturer::withCount('products')
            ->addSelect([
                'total_sold_quantity' => DB::table('products')
                    ->join('purchased_products', 'products.id', '=', 'purchased_products.product_id')
                    ->whereColumn('products.manufacturer_id', 'manufacturers.id')
                    ->select(DB::raw('COALESCE(SUM(purchased_products.quantity), 0)'))
            ])
            ->orderBy('name', 'asc');

        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    if (isset($searchDataModel->name)) {
                        $q->where('name', 'like', '%' . $searchDataModel->name . '%');
                    }
                } else if (is_string($searchDataModel)) {
                    $q->where('name', 'like', '%' . $searchDataModel . '%');
                }
            });
        }

        $perPage = $request->input('per_page', 20);
        $manufacturers = $query->paginate($perPage);
        $manufacturers->withPath(url()->current());

        // تبدیل products_count و total_sold_quantity برای سازگاری
        $manufacturers->getCollection()->transform(function ($manufacturer) {
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
        $fields = $request->validate([
            'name' => 'required|string|max:255|unique:manufacturers,name',
        ]);

        $manufacturer = Manufacturer::create($fields);
        return response($manufacturer, 201);
    }

    /**
     * نمایش جزئیات یک تولیدکننده
     */
    public function show(Manufacturer $manufacturer)
    {
        $manufacturer->loadCount('products');
        $manufacturer->products_count = $manufacturer->products_count ?? 0;
        
        // محاسبه تعداد فروش
        $totalSoldQuantity = DB::table('products')
            ->join('purchased_products', 'products.id', '=', 'purchased_products.product_id')
            ->where('products.manufacturer_id', $manufacturer->id)
            ->sum('purchased_products.quantity');
        
        $manufacturer->total_sold_quantity = (int) ($totalSoldQuantity ?? 0);
        
        return response($manufacturer, 200);
    }

    /**
     * ویرایش تولیدکننده
     */
    public function update(Request $request, Manufacturer $manufacturer)
    {
        $fields = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:manufacturers,name,' . $manufacturer->id,
        ]);

        $manufacturer->update($fields);
        return response($manufacturer, 200);
    }

    /**
     * حذف تولیدکننده
     */
    public function destroy(Manufacturer $manufacturer)
    {
        $manufacturer->delete();
        return response(['message' => 'تولیدکننده با موفقیت حذف شد'], 200);
    }

    /**
     * گزارش فروش بر اساس تولیدکننده
     * نمایش تولیدکنندگان با بیشترین فروش
     */
    public function salesReport(Request $request)
    {
        // فیلتر تاریخ (اختیاری)
        $dateFilter = $request->input('filter');
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // Query برای محاسبه فروش هر تولیدکننده
        $query = DB::table('manufacturers')
            ->leftJoin('products', 'manufacturers.id', '=', 'products.manufacturer_id')
            ->leftJoin('purchased_products', 'products.id', '=', 'purchased_products.product_id')
            ->leftJoin('purchases', 'purchased_products.purchase_id', '=', 'purchases.id')
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

