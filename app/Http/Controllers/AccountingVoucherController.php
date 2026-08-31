<?php

namespace App\Http\Controllers;

use App\Models\AccountingVoucher;
use App\Services\AccountingOpeningService;
use App\Services\AccountingVoucherService;
use App\Services\ChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;
use RuntimeException;

class AccountingVoucherController extends Controller
{
    /**
     * لیست اسناد فروشگاه.
     * GET /api/accounting/vouchers
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if (! AccountingVoucher::tablesReady()) {
            return response()->json([
                'message' => 'جدول سند حسابداری وجود ندارد. migration یا فایل SQL را اجرا کنید.',
            ], 422);
        }

        $query = AccountingVoucher::query()
            ->forAtelier($atelierId)
            ->with(['lines.account'])
            ->orderByDesc('number');

        if ($request->filled('source_type')) {
            $query->where('source_type', $request->input('source_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn (AccountingVoucher $v) => $v->toApiArray());

        return response($page, 200);
    }

    /**
     * ثبت سند دستی (برای تست موتور — به فروش وصل نیست).
     * POST /api/accounting/vouchers
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت سند فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        ChartOfAccountsSeeder::ensureForAtelier($atelierId);

        $fields = $request->validate([
            'date' => 'nullable|string',
            'description' => 'nullable|string|max:255',
            'source_type' => 'nullable|string|max:64',
            'source_id' => 'nullable|integer|min:1',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'nullable|integer',
            'lines.*.account_code' => 'nullable|string|max:32',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.description' => 'nullable|string|max:255',
        ]);

        try {
            $date = $this->parseVoucherDate($fields['date'] ?? null);
            $sourceType = $fields['source_type'] ?? AccountingVoucher::SOURCE_MANUAL;
            if ($sourceType === AccountingVoucher::SOURCE_OPENING) {
                return response()->json([
                    'message' => 'افتتاحیه را از POST /api/accounting/opening ثبت کنید.',
                ], 422);
            }
            $sourceId = (int) ($fields['source_id'] ?? 0);
            if ($sourceId <= 0) {
                $sourceId = (int) AccountingVoucher::query()
                    ->forAtelier($atelierId)
                    ->where('source_type', $sourceType)
                    ->max('source_id') + 1;
                if ($sourceId <= 0) {
                    $sourceId = 1;
                }
            }

            $voucher = AccountingVoucherService::post(
                $atelierId,
                $date,
                $fields['description'] ?? null,
                $sourceType,
                $sourceId,
                $fields['lines']
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'سند ثبت شد.',
            'data' => $voucher->toApiArray(),
        ], 201);
    }

    /**
     * سناریوی ۲-۴ نقشه راه.
     * POST /api/accounting/vouchers/self-test
     */
    public function selfTest(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'تست سند فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        try {
            $result = AccountingVoucherService::selfTest($atelierId);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $ok = $result['balanced_saved']
            && $result['idempotent']
            && $result['unbalanced_rejected']
            && $result['reversed'];

        return response([
            'ok' => $ok,
            'message' => $ok ? 'موتور سند درست کار می‌کند.' : 'یکی از کنترل‌های موتور سند رد شد.',
            'data' => $result,
        ], $ok ? 200 : 422);
    }

    /**
     * مشاهده یک سند.
     * GET /api/accounting/vouchers/{accountingVoucher}
     */
    public function show(Request $request, AccountingVoucher $accountingVoucher)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ((int) $accountingVoucher->atelier_id !== $atelierId) {
            return response()->json(['message' => 'یافت نشد'], 404);
        }

        $accountingVoucher->load(['lines.account']);

        return response(['data' => $accountingVoucher->toApiArray()], 200);
    }

    /**
     * برگشت سند (storno).
     * POST /api/accounting/vouchers/{accountingVoucher}/reverse
     */
    public function reverse(Request $request, AccountingVoucher $accountingVoucher)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null || (int) $accountingVoucher->atelier_id !== $atelierId) {
            return response()->json(['message' => 'یافت نشد'], 404);
        }

        try {
            $storno = AccountingVoucherService::reverse($accountingVoucher);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'سند برگشت خورد.',
            'data' => $storno->toApiArray(),
        ], 200);
    }

    /**
     * مانده نقد عملیاتی را با یک سند افتتاحیه به سرمایه می‌بندد (یک‌بار برای هر فروشگاه).
     * POST /api/accounting/opening
     */
    public function opening(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت افتتاحیه فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $fields = $request->validate([
            'date' => 'nullable|string',
        ]);

        try {
            $date = $this->parseVoucherDate($fields['date'] ?? null);
            $result = AccountingOpeningService::post($atelierId, $date);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['skipped']) {
            return response([
                'ok' => true,
                'already_posted' => false,
                'message' => 'مانده نقد دفتر با حساب‌های فروشگاه یکی است؛ سندی ساخته نشد.',
                'data' => null,
            ], 200);
        }

        $already = (bool) $result['already_posted'];

        return response([
            'ok' => true,
            'already_posted' => $already,
            'message' => $already
                ? 'سند افتتاحیه از قبل ثبت شده است.'
                : 'سند افتتاحیه ثبت شد.',
            'data' => $result['voucher'] ? $result['voucher']->toApiArray() : null,
        ], $already ? 200 : 201);
    }

    protected function parseVoucherDate(?string $date): string
    {
        if (! $date) {
            return now('Asia/Tehran')->toDateString();
        }

        try {
            return Jalalian::fromFormat('Y-m-d', $date)->toCarbon()->toDateString();
        } catch (\Throwable $e) {
            return \Carbon\Carbon::parse($date, 'Asia/Tehran')->toDateString();
        }
    }
}
