<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ReturnedProduct;
use App\Services\ReturnedProductGridService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;

class ReturnedProductController extends Controller
{
    /**
     * گرید برگشت کالا — فیلتر ماه شمسی، هر تراکنش یک سطر + جمع ماه.
     * GET /api/returned-products/grid
     * GET /api/returned-products/grid?year=1404&month=3
     */
    public function grid(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $now = Jalalian::fromCarbon(Carbon::now('Asia/Tehran'));
        $request->validate([
            'year' => 'sometimes|integer|min:1300|max:1500',
            'month' => 'sometimes|integer|min:1|max:12',
        ]);

        $year = $request->has('year')
            ? (int) $request->input('year')
            : (int) $now->getYear();
        $month = $request->has('month')
            ? (int) $request->input('month')
            : (int) $now->getMonth();

        $data = ReturnedProductGridService::gridForMonth($atelierId, $year, $month);

        return response(array_merge($data, [
            'meta' => ['atelier_id' => $atelierId],
        ]), 200);
    }

    /**
     * برگشت کالا بر اساس بارکد — ذخیره قیمت فروش و خرید همان لحظه.
     */
    public function store(Request $request)
    {
        $request->validate([
            'barcode' => 'required|string',
            'notes' => 'nullable|string|max:2000',
        ]);

        $staffAtelierId = $this->staffShopAtelierId($request);
        $userName = null;
        if ($staffAtelierId !== null) {
            $user = $this->requireStaffShopUser($request);
            $userName = trim($user->name.' '.$user->last_name);
        }

        $productQuery = Product::query()->where('barcode', $request->input('barcode'));
        if ($staffAtelierId !== null) {
            $productQuery->where('atelier_id', $staffAtelierId);
        }
        $product = $productQuery->first();

        if (! $product) {
            return response(['error' => 'محصولی با این بارکد یافت نشد'], 404);
        }

        $product->increment('quantity', 1);

        $returnedProduct = ReturnedProduct::create([
            'product_id' => $product->id,
            'atelier_id' => $staffAtelierId ?? $product->atelier_id,
            'sale_price' => $product->sale_price,
            'purchase_price' => $product->purchase_price,
            'user_name' => $userName,
            'notes' => $request->input('notes'),
        ]);

        $returnedProduct->load('product');

        return response([
            'message' => 'کالا با موفقیت برگشت داده شد',
            'row' => ReturnedProductGridService::formatTransactionRow($returnedProduct),
            'returned_product' => $returnedProduct,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'new_quantity' => $product->quantity,
            ],
        ], 201);
    }

    /**
     * لیست برگشتی‌ها (صفحه‌بندی — جستجو).
     */
    public function index(Request $request)
    {
        $query = ReturnedProduct::with('product')->orderBy('id', 'desc');

        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId !== null) {
            $query->forAtelier($atelierId);
        }

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    if (isset($searchDataModel->barcode)) {
                        $q->whereHas('product', function ($productQuery) use ($searchDataModel) {
                            $productQuery->where('barcode', 'like', '%'.$searchDataModel->barcode.'%');
                        });
                    }
                    if (isset($searchDataModel->product_name)) {
                        $q->orWhereHas('product', function ($productQuery) use ($searchDataModel) {
                            $productQuery->where('name', 'like', '%'.$searchDataModel->product_name.'%');
                        });
                    }
                } elseif (is_string($searchDataModel)) {
                    $q->whereHas('product', function ($productQuery) use ($searchDataModel) {
                        $productQuery->where('barcode', 'like', '%'.$searchDataModel.'%')
                            ->orWhere('name', 'like', '%'.$searchDataModel.'%');
                    });
                }
            });
        }

        $returnedProducts = $query->paginate();

        return response($returnedProducts, 200);
    }
}
