<?php

namespace App\Http\Controllers;

use App\Models\ManualTrade;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Morilog\Jalali\Jalalian;

class ManualTradeController extends Controller
{
    /**
     * لیست سندهای خرید و فروش دستی
     * GET /api/manual-trades?type=purchase|sale
     */
    public function index(Request $request)
    {
        if (! ManualTrade::tableReady()) {
            return response()->json([
                'message' => 'جدول سند خرید و فروش وجود ندارد. SQL را اجرا کنید.',
            ], 503);
        }

        $atelierId = $this->shopAtelierIdOrAbort($request);
        $query = ManualTrade::query()
            ->where('atelier_id', $atelierId)
            ->orderByDesc('id');

        if ($this->supportsPaymentAccount('manual_trades')) {
            $query->with('shopAccount');
            if ($request->filled('shop_account_id')) {
                $query->where('shop_account_id', (int) $request->input('shop_account_id'));
            }
        }

        if ($request->filled('type') && in_array($request->input('type'), ManualTrade::types(), true)) {
            $query->where('type', $request->input('type'));
        }

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    if (isset($searchDataModel->title)) {
                        $q->where('title', 'like', '%'.$searchDataModel->title.'%');
                    }
                    if (isset($searchDataModel->description)) {
                        $q->orWhere('description', 'like', '%'.$searchDataModel->description.'%');
                    }
                    if (isset($searchDataModel->user_name)) {
                        $q->orWhere('user_name', 'like', '%'.$searchDataModel->user_name.'%');
                    }
                    if (isset($searchDataModel->type) && in_array($searchDataModel->type, ManualTrade::types(), true)) {
                        $q->orWhere('type', $searchDataModel->type);
                    }
                } elseif (is_string($searchDataModel) && $searchDataModel !== '') {
                    $q->where('title', 'like', '%'.$searchDataModel.'%')
                        ->orWhere('description', 'like', '%'.$searchDataModel.'%')
                        ->orWhere('user_name', 'like', '%'.$searchDataModel.'%');
                }
            });
        } elseif ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhere('user_name', 'like', '%'.$search.'%');
            });
        }

        $this->applyDateFilter($query, $request);

        $totalAmount = (float) (clone $query)->sum('amount');
        $totalPurchases = (float) (clone $query)->where('type', ManualTrade::TYPE_PURCHASE)->sum('amount');
        $totalSales = (float) (clone $query)->where('type', ManualTrade::TYPE_SALE)->sum('amount');

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = $query->paginate($perPage);
        $payload = $page->toArray();
        $payload['total_amount'] = $totalAmount;
        $payload['total_purchases'] = $totalPurchases;
        $payload['total_sales'] = $totalSales;

        return response($payload, 200);
    }

    public function store(Request $request)
    {
        if (! ManualTrade::tableReady()) {
            return response()->json([
                'message' => 'جدول سند خرید و فروش وجود ندارد. SQL را اجرا کنید.',
            ], 503);
        }

        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت سند فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $fields = $request->validate(array_merge([
            'type' => ['required', Rule::in(ManualTrade::types())],
            'amount' => 'required|numeric|min:0',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'date' => 'nullable|date',
        ], $this->paymentAccountRules('manual_trades')));

        $accountError = $this->paymentAccountError($atelierId, $fields['shop_account_id'] ?? null);
        if ($accountError) {
            return response()->json(['message' => $accountError], 422);
        }

        $user = $this->shopRequestActor($request);
        if (! $user) {
            return response(['error' => 'کاربر احراز هویت نشده است'], 401);
        }

        $fields['user_name'] = trim($user->name.' '.$user->last_name);
        $fields['date'] = ! empty($fields['date'])
            ? Carbon::parse($fields['date'])->format('Y-m-d')
            : Carbon::now()->format('Y-m-d');
        $fields['atelier_id'] = $atelierId;

        $trade = ManualTrade::create($fields);

        return response($this->withAccount($trade), 201);
    }

    public function show(Request $request, ManualTrade $manualTrade)
    {
        $this->assertModelBelongsToStaffAtelier($request, $manualTrade);

        return response($this->withAccount($manualTrade), 200);
    }

    public function update(Request $request, ManualTrade $manualTrade)
    {
        $this->assertModelBelongsToStaffAtelier($request, $manualTrade);

        $fields = $request->validate(array_merge([
            'type' => ['sometimes', 'required', Rule::in(ManualTrade::types())],
            'amount' => 'sometimes|required|numeric|min:0',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'date' => 'sometimes|required|date',
        ], $this->paymentAccountRules('manual_trades')));

        if (array_key_exists('shop_account_id', $fields)) {
            $accountError = $this->paymentAccountError((int) $manualTrade->atelier_id, $fields['shop_account_id']);
            if ($accountError) {
                return response()->json(['message' => $accountError], 422);
            }
        }

        $manualTrade->update($fields);

        return response($this->withAccount($manualTrade->fresh()), 200);
    }

    public function destroy(Request $request, ManualTrade $manualTrade)
    {
        $this->assertModelBelongsToStaffAtelier($request, $manualTrade);

        $manualTrade->delete();

        return response(['message' => 'سند حذف شد'], 200);
    }

    protected function withAccount(ManualTrade $trade): ManualTrade
    {
        return $this->attachPaymentAccount($trade);
    }

    private function applyDateFilter($query, Request $request): void
    {
        if (! $request->has('filter')) {
            return;
        }

        if ($request->filter === 'today') {
            $query->whereDate('date', Carbon::today());
        } elseif ($request->filter === 'week') {
            $now = Jalalian::now();
            $dayOfWeek = $now->getDayOfWeek();
            $startOfWeek = Jalalian::now()->subDays($dayOfWeek)->toCarbon()->startOfDay();
            $endOfWeek = Jalalian::now()->addDays(6 - $dayOfWeek)->toCarbon()->endOfDay();
            $query->whereBetween('date', [$startOfWeek, $endOfWeek]);
        } elseif ($request->filter === 'month') {
            $now = Jalalian::now();
            $startOfMonth = (new Jalalian($now->getYear(), $now->getMonth(), 1))->toCarbon()->startOfDay();
            $endOfMonth = (new Jalalian($now->getYear(), $now->getMonth(), 1))->addMonths(1)->subDays(1)->toCarbon()->endOfDay();
            $query->whereBetween('date', [$startOfMonth, $endOfMonth]);
        } elseif ($request->filter === 'year') {
            $year = Jalalian::now()->getYear();
            $startOfYear = (new Jalalian($year, 1, 1))->toCarbon()->startOfDay();
            $endOfYear = (new Jalalian($year, 12, 29))->toCarbon()->endOfDay();
            $query->whereBetween('date', [$startOfYear, $endOfYear]);
        } elseif ($request->filter === 'range') {
            if ($request->has('from_date')) {
                $fromDate = json_decode($request->input('from_date'));
                $fromCarbon = (new Jalalian($fromDate->year, $fromDate->month, $fromDate->day))->toCarbon()->startOfDay();
                $query->where('date', '>=', $fromCarbon);
            }
            if ($request->has('to_date')) {
                $toDate = json_decode($request->input('to_date'));
                $toCarbon = (new Jalalian($toDate->year, $toDate->month, $toDate->day))->toCarbon()->endOfDay();
                $query->where('date', '<=', $toCarbon);
            }
        }
    }
}
