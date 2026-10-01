<?php

namespace App\Http\Controllers;

use App\Console\Commands\AccountingFlowSelfTest;
use App\Models\AccountingVoucher;
use App\Services\AccountingOpeningService;
use App\Services\AccountingPeriodCloseService;
use App\Services\AccountingReportService;
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
        $atelierId = $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::ACCOUNTING, 'حسابداری برای این فروشگاه فعال نیست.');
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
        $from = AccountingReportService::parseDate($request->input('from'));
        $to = AccountingReportService::parseDate($request->input('to'));
        if ($from) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('date', '<=', $to);
        }

        $accountId = (int) $request->input('account_id', 0);
        if ($accountId > 0) {
            $accountIds = $this->accountWithDescendantIds($atelierId, $accountId);
            $query->whereHas('lines', fn ($q) => $q->whereIn('account_id', $accountIds));
        }

        $amount = $this->normalizeSearchNumber((string) $request->input('amount', ''));
        if ($amount !== '' && (float) $amount > 0) {
            $value = round((float) $amount, 2);
            $query->whereHas('lines', fn ($q) => $q->where(function ($w) use ($value) {
                $w->where('debit', $value)->orWhere('credit', $value);
            }));
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $numeric = $this->normalizeSearchNumber($search);
            $query->where(function ($w) use ($search, $numeric) {
                $w->where('description', 'like', '%'.$search.'%')
                    ->orWhereHas('lines', fn ($q) => $q->where('description', 'like', '%'.$search.'%'));
                if ($numeric !== '' && ctype_digit($numeric)) {
                    $w->orWhere('number', (int) $numeric)->orWhere('source_id', (int) $numeric);
                }
            });
        }

        $closed = AccountingPeriodCloseService::closedThrough($atelierId);
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn (AccountingVoucher $v) => $this->voucherWithLock($v, $closed));

        return response($page, 200);
    }

    /**
     * @return list<int>
     */
    protected function accountWithDescendantIds(int $atelierId, int $accountId): array
    {
        $childrenByParent = [];
        foreach (\App\Models\AccountingAccount::query()->forAtelier($atelierId)->get(['id', 'parent_id']) as $row) {
            $childrenByParent[(int) $row->parent_id][] = (int) $row->id;
        }

        $ids = [];
        $stack = [$accountId];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($ids[$id])) {
                continue;
            }
            $ids[$id] = true;
            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return array_keys($ids);
    }

    protected function normalizeSearchNumber(string $value): string
    {
        $value = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ',' => '', '٬' => '', '،' => '', ' ' => '', '#' => '',
        ]);

        return trim($value);
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
            if ($sourceType === AccountingVoucher::SOURCE_YEAR_CLOSE) {
                return response()->json([
                    'message' => 'بستن دوره را از POST /api/accounting/period-close ثبت کنید.',
                ], 422);
            }
            if ($sourceType === AccountingVoucher::SOURCE_BALANCE_ADJUST) {
                return response()->json([
                    'message' => 'اصلاح مانده حساب را از POST /api/shop-accounts/set-balances ثبت کنید.',
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
     * تست زندهٔ مسیرهای مالی (فروش، برگشت، فاکتور، هزینه، حقوق، تولید).
     * GET/POST /api/accounting/flow-self-test?key=...&atelier_id=13
     */
    public function flowSelfTest(Request $request)
    {
        $expected = (string) config('app.accounting_flow_test_key', 'flow-test-shop-13-zarrin');
        $given = (string) $request->query('key', $request->input('key', ''));
        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'کلید نامعتبر است.'], 403);
        }

        @set_time_limit(180);
        $atelierId = (int) $request->query('atelier_id', $request->input('atelier_id', 13));
        $cleanup = $request->boolean('cleanup');

        try {
            $result = app(AccountingFlowSelfTest::class)->runFor($atelierId, $cleanup);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 500);
        }

        if (! empty($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json($result, ! empty($result['ok']) ? 200 : 422);
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

        return response([
            'data' => $this->voucherWithLock($accountingVoucher, AccountingPeriodCloseService::closedThrough($atelierId)),
        ], 200);
    }

    /**
     * @return array<string, mixed>
     */
    protected function voucherWithLock(AccountingVoucher $voucher, ?string $closedThrough): array
    {
        $row = $voucher->toApiArray();
        $row['locked'] = $closedThrough !== null
            && $voucher->date
            && $voucher->date->toDateString() <= $closedThrough;

        return $row;
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
