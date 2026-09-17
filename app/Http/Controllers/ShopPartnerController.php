<?php

namespace App\Http\Controllers;

use App\Models\ShopPartner;
use App\Models\ShopPartnerSettlement;
use App\Services\AccountingPartnerSettlementPoster;
use App\Services\ShopPartnerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ShopPartnerController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if (! ShopPartner::tableReady()) {
            return response(['data' => [], 'message' => 'جداول شرکا هنوز ساخته نشده است.'], 200);
        }

        $activeOnly = $request->boolean('active_only');

        return response([
            'data' => ShopPartnerService::listPartners($atelierId, $activeOnly),
            'total_capital' => round(
                (float) ShopPartner::query()->forAtelier($atelierId)->active()->sum('capital_amount'),
                2
            ),
        ], 200);
    }

    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response(['message' => 'ثبت شریک فقط با حساب پرسنل فروشگاه امکان‌پذیر است.'], 422);
        }
        if (! ShopPartner::tableReady()) {
            return response(['message' => 'جداول شرکا آماده نیست. SQL را اجرا کنید.'], 422);
        }

        $fields = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'capital_amount' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $partner = ShopPartner::create([
            'atelier_id' => $atelierId,
            'name' => trim($fields['name']),
            'phone' => $fields['phone'] ?? null,
            'capital_amount' => round((float) $fields['capital_amount'], 2),
            'is_active' => array_key_exists('is_active', $fields) ? (bool) $fields['is_active'] : true,
            'notes' => $fields['notes'] ?? null,
        ]);

        return response([
            'message' => 'شریک ثبت شد.',
            'data' => ShopPartnerService::serializePartner($partner),
        ], 201);
    }

    public function update(Request $request, ShopPartner $shopPartner)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response(['message' => 'ویرایش فقط با حساب پرسنل فروشگاه امکان‌پذیر است.'], 422);
        }
        if ((int) $shopPartner->atelier_id !== (int) $atelierId) {
            return response(['message' => 'این شریک متعلق به فروشگاه شما نیست.'], 403);
        }

        $fields = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'capital_amount' => 'sometimes|required|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        if (isset($fields['name'])) {
            $shopPartner->name = trim($fields['name']);
        }
        if (array_key_exists('phone', $fields)) {
            $shopPartner->phone = $fields['phone'];
        }
        if (isset($fields['capital_amount'])) {
            $shopPartner->capital_amount = round((float) $fields['capital_amount'], 2);
        }
        if (array_key_exists('is_active', $fields)) {
            $shopPartner->is_active = (bool) $fields['is_active'];
        }
        if (array_key_exists('notes', $fields)) {
            $shopPartner->notes = $fields['notes'];
        }
        $shopPartner->save();

        return response([
            'message' => 'شریک به‌روز شد.',
            'data' => ShopPartnerService::serializePartner($shopPartner->fresh()),
        ], 200);
    }

    public function destroy(Request $request, ShopPartner $shopPartner)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response(['message' => 'حذف فقط با حساب پرسنل فروشگاه امکان‌پذیر است.'], 422);
        }
        if ((int) $shopPartner->atelier_id !== (int) $atelierId) {
            return response(['message' => 'این شریک متعلق به فروشگاه شما نیست.'], 403);
        }

        $shopPartner->delete();

        return response(['message' => 'شریک حذف شد.'], 200);
    }

    public function profitPreview(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $request->validate([
            'to' => 'nullable|date',
        ]);

        return response(ShopPartnerService::profitPreview(
            $atelierId,
            $request->input('to')
        ), 200);
    }

    public function settle(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response(['message' => 'تقسیم سود فقط با حساب پرسنل فروشگاه امکان‌پذیر است.'], 422);
        }

        $fields = $request->validate([
            'shop_account_id' => 'required|integer|exists:shop_accounts,id',
            'to' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        $accountError = $this->paymentAccountError($atelierId, $fields['shop_account_id']);
        if ($accountError) {
            return response(['message' => $accountError], 422);
        }

        $userName = null;
        try {
            $user = $this->requireStaffShopUser($request);
            $userName = trim($user->name.' '.$user->last_name);
        } catch (\Throwable $e) {
            /* optional */
        }

        try {
            $settlement = ShopPartnerService::settle($atelierId, [
                'shop_account_id' => (int) $fields['shop_account_id'],
                'to' => $fields['to'] ?? null,
                'notes' => $fields['notes'] ?? null,
                'user_name' => $userName,
            ]);
        } catch (InvalidArgumentException $e) {
            return response(['message' => $e->getMessage(), 'error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response(['message' => $e->getMessage(), 'error' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'تقسیم سود ثبت شد و از حساب برداشت شد.',
            'data' => $this->serializeSettlement($settlement),
        ], 201);
    }

    public function settlements(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if (! ShopPartnerSettlement::tableReady()) {
            return response(['data' => []], 200);
        }

        $rows = ShopPartnerSettlement::query()
            ->where('atelier_id', $atelierId)
            ->with(['lines', 'shopAccount:id,name,type'])
            ->orderByDesc('settled_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (ShopPartnerSettlement $s) => $this->serializeSettlement($s))
            ->values();

        return response(['data' => $rows], 200);
    }

    public function showSettlement(Request $request, ShopPartnerSettlement $settlement)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ((int) $settlement->atelier_id !== (int) $atelierId) {
            return response(['message' => 'این تسویه متعلق به فروشگاه شما نیست.'], 403);
        }

        $settlement->load(['lines', 'shopAccount:id,name,type']);

        return response(['data' => $this->serializeSettlement($settlement)], 200);
    }

    public function destroySettlement(Request $request, ShopPartnerSettlement $settlement)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response(['message' => 'حذف فقط با حساب پرسنل فروشگاه امکان‌پذیر است.'], 422);
        }
        if ((int) $settlement->atelier_id !== (int) $atelierId) {
            return response(['message' => 'این تسویه متعلق به فروشگاه شما نیست.'], 403);
        }

        DB::transaction(function () use ($settlement) {
            AccountingPartnerSettlementPoster::reverse($settlement);
            $settlement->lines()->delete();
            $settlement->delete();
        });

        return response(['message' => 'تسویه حذف و سند حسابداری برگشت خورد.'], 200);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeSettlement(ShopPartnerSettlement $settlement): array
    {
        $account = $settlement->shopAccount;

        return [
            'id' => (int) $settlement->id,
            'atelier_id' => (int) $settlement->atelier_id,
            'settled_at' => optional($settlement->settled_at)->toDateString(),
            'settled_at_jalali' => $settlement->settled_at_jalali,
            'period_from' => optional($settlement->period_from)->toDateString(),
            'period_from_jalali' => $settlement->period_from_jalali,
            'period_to' => optional($settlement->period_to)->toDateString(),
            'period_to_jalali' => $settlement->period_to_jalali,
            'net_profit' => round((float) $settlement->net_profit, 2),
            'total_distributed' => round((float) $settlement->total_distributed, 2),
            'shop_account_id' => $settlement->shop_account_id ? (int) $settlement->shop_account_id : null,
            'shop_account' => $account ? [
                'id' => (int) $account->id,
                'name' => $account->name,
                'type' => $account->type,
            ] : null,
            'user_name' => $settlement->user_name,
            'notes' => $settlement->notes,
            'lines' => $settlement->lines->map(function ($line) {
                return [
                    'id' => (int) $line->id,
                    'partner_id' => $line->partner_id ? (int) $line->partner_id : null,
                    'partner_name' => $line->partner_name,
                    'capital_amount' => round((float) $line->capital_amount, 2),
                    'share_percent' => round((float) $line->share_percent, 4),
                    'amount' => round((float) $line->amount, 2),
                ];
            })->values()->all(),
            'created_at' => $settlement->created_at,
        ];
    }
}
