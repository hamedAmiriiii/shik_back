<?php

namespace App\Http\Controllers;

use App\Models\AccountingAuditLog;
use App\Models\AccountingVoucher;
use App\Models\User;
use App\Services\AccountingAuditorService;
use App\Services\AccountingPeriodCloseService;
use App\Services\ChartOfAccountsSeeder;
use App\Services\ShopFeatureFlags;
use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;
use RuntimeException;

class AccountingAuditorController extends Controller
{
    /**
     * دوره‌های مالی و اینکه کاربر فعلی می‌تواند در دورهٔ بسته ویرایش کند یا نه.
     * GET /api/accounting/periods
     */
    public function periods(Request $request)
    {
        $atelierId = $this->assertShopFeature($request, ShopFeatureFlags::ACCOUNTING, 'حسابداری برای این فروشگاه فعال نیست.');
        if ($fail = $this->tablesGuard()) {
            return $fail;
        }

        $meta = AccountingPeriodCloseService::openPeriodMeta($atelierId);

        return response(['data' => [
            'periods' => AccountingPeriodCloseService::periods($atelierId),
            'closed_through' => $meta['closed_through'],
            'today' => $meta['today'],
            'can_edit_closed' => AccountingAuditorService::canEditClosedPeriods($this->actorUser($request)),
        ]], 200);
    }

    /**
     * لاگ تغییرات حسابرس؛ صاحب فروشگاه و حسابرس هر دو می‌بینند.
     * GET /api/accounting/audit-log?voucher_id=&page=
     */
    public function auditLog(Request $request)
    {
        $atelierId = $this->assertShopFeature($request, ShopFeatureFlags::ACCOUNTING, 'حسابداری برای این فروشگاه فعال نیست.');
        if (! AccountingAuditLog::tableReady()) {
            return response()->json([
                'message' => 'جدول accounting_audit_logs وجود ندارد. migration یا SQL لاگ حسابرس را اجرا کنید.',
            ], 422);
        }

        $query = AccountingAuditLog::query()
            ->forAtelier($atelierId)
            ->with(['user:id,name', 'voucher:id,number', 'relatedVoucher:id,number'])
            ->orderByDesc('id');
        if ($request->filled('voucher_id')) {
            $voucherId = (int) $request->input('voucher_id');
            $query->where(function ($q) use ($voucherId) {
                $q->where('voucher_id', $voucherId)->orWhere('related_voucher_id', $voucherId);
            });
        }
        if ($request->boolean('closed_only')) {
            $query->whereNotNull('closed_through');
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn (AccountingAuditLog $log) => $log->toApiArray());

        return response($page, 200);
    }

    /**
     * سند دستی حسابرس با هر تاریخی (حتی دورهٔ بسته).
     * POST /api/accounting/auditor/vouchers
     */
    public function store(Request $request)
    {
        [$atelierId, $user] = $this->auditorContext($request);
        $fields = $this->validateVoucher($request);

        try {
            $result = AccountingAuditorService::create(
                $atelierId,
                (int) $user->id,
                $this->parseDate($fields['date'] ?? null),
                $fields['description'] ?? null,
                $fields['lines'],
                $fields['reason'] ?? null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => $result['close_adjust']
                ? 'سند ثبت شد و اثرش در بستن همان دوره به سود انباشته منتقل شد.'
                : 'سند ثبت شد.',
            'data' => $result['voucher']->toApiArray(),
            'close_adjust' => $result['close_adjust'] ? $result['close_adjust']->toApiArray(false) : null,
        ], 201);
    }

    /**
     * اصلاح سند دستی: برگشت با همان تاریخ + سند جدید.
     * POST /api/accounting/auditor/vouchers/{accountingVoucher}/correct
     */
    public function correct(Request $request, AccountingVoucher $accountingVoucher)
    {
        [$atelierId, $user] = $this->auditorContext($request);
        $fields = $this->validateVoucher($request);

        try {
            $result = AccountingAuditorService::correct(
                $atelierId,
                (int) $user->id,
                $accountingVoucher,
                ! empty($fields['date']) ? $this->parseDate($fields['date']) : null,
                $fields['description'] ?? null,
                $fields['lines'],
                $fields['reason'] ?? null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'سند '.$accountingVoucher->number.' اصلاح شد.',
            'data' => $result['voucher']->toApiArray(),
            'storno' => $result['storno']->toApiArray(false),
        ], 201);
    }

    /**
     * برگشت سند دستی با تاریخ خودِ سند.
     * POST /api/accounting/auditor/vouchers/{accountingVoucher}/reverse
     */
    public function reverse(Request $request, AccountingVoucher $accountingVoucher)
    {
        [$atelierId, $user] = $this->auditorContext($request);
        $fields = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $storno = AccountingAuditorService::reverse($atelierId, (int) $user->id, $accountingVoucher, $fields['reason'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'سند برگشت خورد.',
            'data' => $storno->toApiArray(),
        ], 200);
    }

    /**
     * تعدیلات سنواتی در دورهٔ باز.
     * POST /api/accounting/auditor/prior-year-adjust
     */
    public function priorYearAdjust(Request $request)
    {
        [$atelierId, $user] = $this->auditorContext($request);
        $fields = $this->validateVoucher($request);

        try {
            $voucher = AccountingAuditorService::priorYearAdjust(
                $atelierId,
                (int) $user->id,
                ! empty($fields['date']) ? $this->parseDate($fields['date']) : null,
                $fields['description'] ?? null,
                $fields['lines'],
                $fields['reason'] ?? null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'سند تعدیلات سنواتی ثبت شد.',
            'data' => $voucher->toApiArray(),
        ], 201);
    }

    /**
     * @return array{0: int, 1: User}
     */
    protected function auditorContext(Request $request): array
    {
        $atelierId = $this->staffShopAtelierId($request);
        $user = $this->actorUser($request);
        if ($atelierId === null || ! $user) {
            abort(response()->json(['message' => 'این کار فقط با حساب متصل به فروشگاه امکان‌پذیر است.'], 422));
        }
        if (! ShopFeatureFlags::enabled($atelierId, ShopFeatureFlags::ACCOUNTING)) {
            abort(response()->json(['message' => 'حسابداری برای این فروشگاه فعال نیست.'], 403));
        }
        if (! AccountingAuditorService::canEditClosedPeriods($user)) {
            abort(response()->json(['message' => 'فقط حسابرس به این بخش دسترسی دارد.'], 403));
        }
        if ($fail = $this->tablesGuard()) {
            abort($fail);
        }
        ChartOfAccountsSeeder::ensureForAtelier($atelierId);

        return [$atelierId, $user];
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateVoucher(Request $request): array
    {
        return $request->validate([
            'date' => 'nullable|string',
            'description' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'nullable|integer',
            'lines.*.account_code' => 'nullable|string|max:32',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.description' => 'nullable|string|max:255',
        ]);
    }

    protected function actorUser(Request $request): ?User
    {
        $actor = $this->shopRequestActor($request);

        return $actor instanceof User ? $actor : null;
    }

    protected function tablesGuard()
    {
        if (! AccountingVoucher::tablesReady() || ! \App\Models\AccountingAccount::tableReady()) {
            return response()->json([
                'message' => 'جدول سند حسابداری وجود ندارد. migration یا فایل SQL را اجرا کنید.',
            ], 422);
        }

        return null;
    }

    protected function parseDate(?string $date): string
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
