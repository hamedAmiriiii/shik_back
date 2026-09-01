<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchasedProduct;
use App\Models\ReturnedProduct;
use App\Services\PurchaseItemReturnService;
use App\Services\ReturnedProductGridService;
use App\Tools\ProductQuantityTools;
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
     * برگشت کالا بر اساس بارکد — از آخرین خط فروش باقی‌مانده، با تسویه پول/بدهی.
     */
    public function store(Request $request)
    {
        $request->validate([
            'barcode' => 'required|string',
            'notes' => 'nullable|string|max:2000',
            'phone' => 'nullable|string|max:20',
            'quantity' => 'nullable|numeric|min:0.001',
        ]);

        $staffAtelierId = $this->staffShopAtelierId($request);
        if ($staffAtelierId === null) {
            return response()->json([
                'message' => 'برگشت با بارکد فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }
        $user = $this->requireStaffShopUser($request);
        $userName = trim($user->name.' '.$user->last_name);

        $product = Product::query()
            ->where('barcode', $request->input('barcode'))
            ->where('atelier_id', $staffAtelierId)
            ->first();

        if (! $product) {
            return response(['error' => 'محصولی با این بارکد یافت نشد'], 404);
        }

        $line = PurchasedProduct::query()
            ->where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->whereHas('purchase', function ($q) use ($staffAtelierId) {
                $q->where('atelier_id', $staffAtelierId);
            })
            ->orderByDesc('id')
            ->first();

        if (! $line) {
            return response([
                'error' => 'فاکتور باز با این بارکد یافت نشد. برگشت باید از روی فاکتور باشد تا پول یا بدهی تسویه شود.',
            ], 422);
        }

        $line->load(['purchase.installments', 'purchase.cheque', 'product']);

        $unitType = $product->unit_type ?? Product::UNIT_PIECE;
        $qty = $request->filled('quantity')
            ? ProductQuantityTools::normalize($request->input('quantity'), $unitType)
            : ProductQuantityTools::normalize(1, $unitType);

        try {
            $result = PurchaseItemReturnService::processReturn(
                $line->purchase,
                $line,
                $qty,
                $userName,
                $request->input('notes'),
                $request->input('phone')
            );
        } catch (\InvalidArgumentException $e) {
            return response(['error' => $e->getMessage(), 'message' => $e->getMessage()], 422);
        }

        $returnedProduct = null;
        if ($result['log']->product_id && \Illuminate\Support\Facades\Schema::hasColumn('returned_products', 'purchase_id')) {
            $returnedProduct = ReturnedProduct::query()
                ->where('purchase_id', $result['log']->purchase_id)
                ->where('product_id', $result['log']->product_id)
                ->orderByDesc('id')
                ->first();
        }

        return response([
            'message' => 'کالا از روی فاکتور برگشت داده شد.',
            'row' => $returnedProduct
                ? ReturnedProductGridService::formatTransactionRow($returnedProduct)
                : $result['row'],
            'returned_item' => $result['returned_item'],
            'returned_product' => $returnedProduct,
            'purchase' => $result['purchase'],
            'customer_credit' => $result['customer_credit'],
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'new_quantity' => $product->fresh()->quantity,
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
