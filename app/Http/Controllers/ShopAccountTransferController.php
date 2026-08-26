<?php

namespace App\Http\Controllers;

use App\Models\ShopAccount;
use App\Models\ShopAccountTransfer;
use App\Services\ShopAccountBalanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopAccountTransferController extends Controller
{
    /**
     * لیست شارژهای تنخواه.
     * GET /api/shop-account-transfers
     * GET /api/shop-account-transfers?to_shop_account_id=5
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        if (! Schema::hasTable('shop_account_transfers')) {
            return response(['data' => [], 'total' => 0, 'total_amount' => 0.0], 200);
        }

        $request->validate([
            'from_shop_account_id' => 'sometimes|integer',
            'to_shop_account_id' => 'sometimes|integer',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = ShopAccountTransfer::query()
            ->forAtelier($atelierId)
            ->with(['fromAccount', 'toAccount'])
            ->when(
                $request->filled('from_shop_account_id'),
                fn ($q) => $q->where('from_shop_account_id', (int) $request->input('from_shop_account_id'))
            )
            ->when(
                $request->filled('to_shop_account_id'),
                fn ($q) => $q->where('to_shop_account_id', (int) $request->input('to_shop_account_id'))
            )
            ->orderByDesc('id');

        $totalAmount = (float) (clone $query)->sum('amount');

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $transfers = $query->paginate($perPage, ['*'], 'page', max(1, (int) $request->input('page', 1)));
        $transfers->appends($request->except('page'));
        $transfers->getCollection()->transform(fn (ShopAccountTransfer $t) => $this->serialize($t));

        $payload = $transfers->toArray();
        $payload['total_amount'] = $totalAmount;

        return response($payload, 200);
    }

    /**
     * شارژ تنخواه از یکی از حساب‌های اصلی فروشگاه.
     * POST /api/shop-account-transfers
     * { "from_shop_account_id": 1, "to_shop_account_id": 7, "amount": 5000000, "title": "شارژ هفتگی" }
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'شارژ تنخواه فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        if (! Schema::hasTable('shop_account_transfers')) {
            return response()->json([
                'message' => 'جدول shop_account_transfers وجود ندارد. migration یا فایل SQL را اجرا کنید.',
            ], 422);
        }

        $fields = $request->validate([
            'from_shop_account_id' => 'required|integer|exists:shop_accounts,id',
            'to_shop_account_id' => 'required|integer|different:from_shop_account_id|exists:shop_accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'date' => 'nullable|date',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $from = ShopAccount::find($fields['from_shop_account_id']);
        $to = ShopAccount::find($fields['to_shop_account_id']);

        $error = $this->validateAccounts($atelierId, $from, $to);
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $user = $this->requireStaffShopUser($request);
        $amount = round((float) $fields['amount'], 2);
        $date = isset($fields['date'])
            ? Carbon::parse($fields['date'])->setTimezone('Asia/Tehran')->format('Y-m-d')
            : Carbon::now('Asia/Tehran')->format('Y-m-d');

        $transfer = DB::transaction(fn () => ShopAccountTransfer::create([
            'atelier_id' => $atelierId,
            'from_shop_account_id' => $from->id,
            'to_shop_account_id' => $to->id,
            'amount' => $amount,
            'date' => $date,
            'title' => $fields['title'] ?? 'شارژ تنخواه '.$to->name,
            'description' => $fields['description'] ?? null,
            'user_name' => trim($user->name.' '.$user->last_name),
        ]));

        $balances = ShopAccountBalanceService::balances($atelierId, [$from->id, $to->id]);

        return response([
            'message' => 'شارژ تنخواه ثبت شد.',
            'data' => $this->serialize($transfer->fresh(['fromAccount', 'toAccount'])),
            'balances' => [
                'from' => ['id' => $from->id, 'name' => $from->name, 'balance' => round((float) ($balances[$from->id] ?? 0), 2)],
                'to' => ['id' => $to->id, 'name' => $to->name, 'balance' => round((float) ($balances[$to->id] ?? 0), 2)],
            ],
        ], 201);
    }

    /**
     * حذف شارژ (موجودی هر دو حساب برمی‌گردد).
     * DELETE /api/shop-account-transfers/{shopAccountTransfer}
     */
    public function destroy(Request $request, ShopAccountTransfer $shopAccountTransfer)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null || (int) $shopAccountTransfer->atelier_id !== $atelierId) {
            return response()->json(['message' => 'یافت نشد'], 404);
        }

        $accountIds = [
            (int) $shopAccountTransfer->from_shop_account_id,
            (int) $shopAccountTransfer->to_shop_account_id,
        ];
        $shopAccountTransfer->delete();

        return response([
            'message' => 'شارژ تنخواه حذف شد.',
            'balances' => ShopAccountBalanceService::balances($atelierId, $accountIds),
        ], 200);
    }

    protected function validateAccounts(int $atelierId, ?ShopAccount $from, ?ShopAccount $to): ?string
    {
        if (! $from || (int) $from->atelier_id !== $atelierId) {
            return 'حساب مبدأ متعلق به این فروشگاه نیست.';
        }
        if (! $to || (int) $to->atelier_id !== $atelierId) {
            return 'حساب مقصد متعلق به این فروشگاه نیست.';
        }
        if (! $from->is_active || ! $to->is_active) {
            return 'حساب‌های غیرفعال قابل استفاده نیستند.';
        }
        if ($from->isPettyCash()) {
            return 'شارژ فقط از حساب‌های اصلی فروشگاه امکان‌پذیر است، نه از تنخواه.';
        }
        if (! $to->isPettyCash()) {
            return 'مقصد شارژ باید یک حساب تنخواه باشد.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(ShopAccountTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'amount' => (float) $transfer->amount,
            'date' => $transfer->date,
            'title' => $transfer->title,
            'description' => $transfer->description,
            'user_name' => $transfer->user_name,
            'from_account' => $transfer->fromAccount ? [
                'id' => $transfer->fromAccount->id,
                'name' => $transfer->fromAccount->name,
                'type' => $transfer->fromAccount->type,
            ] : null,
            'to_account' => $transfer->toAccount ? [
                'id' => $transfer->toAccount->id,
                'name' => $transfer->toAccount->name,
                'type' => $transfer->toAccount->type,
            ] : null,
            'created_at' => $transfer->created_at,
        ];
    }
}
