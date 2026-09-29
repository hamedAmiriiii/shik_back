<?php

namespace App\Http\Controllers;

use App\Models\FormalInvoiceSellerProfile;
use App\Models\MoadianDocument;
use App\Models\MoadianShopSetting;
use App\Models\MoadianStuffId;
use App\Services\Moadian\MoadianConfigException;
use App\Services\Moadian\MoadianCrypto;
use App\Services\Moadian\MoadianDocumentService;
use App\Services\Moadian\MoadianRunner;
use App\Services\ShopFeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * سامانه مؤدیان — زیر منوی حسابداری. طرح: docs/moadian-integration-design.md
 */
class MoadianController extends Controller
{
    private const FEATURE_MESSAGE = 'حسابداری برای این فروشگاه فعال نیست.';

    /** GET /api/accounting/moadian/summary */
    public function summary(Request $request)
    {
        $atelierId = $this->readAtelier($request);
        $settings = MoadianShopSetting::forAtelier($atelierId);

        $counts = MoadianDocument::query()
            ->where('atelier_id', $atelierId)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        $missingSstid = 0;
        if (Schema::hasColumn('products', 'moadian_sstid') && trim((string) $settings->default_sstid) === '') {
            $missingSstid = (int) DB::table('products')
                ->where('atelier_id', $atelierId)
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->whereNull('moadian_sstid')->orWhere('moadian_sstid', '');
                })
                ->count();
        }

        return response([
            'data' => [
                'enabled' => (bool) $settings->enabled,
                'environment' => $settings->environment ?? MoadianShopSetting::ENV_SANDBOX,
                'paused_reason' => $settings->paused_reason,
                'problems' => $settings->exists ? $settings->readinessProblems() : [],
                'last_run_at' => $this->jalali($settings->last_run_at),
                'counts' => [
                    'queued' => (int) ($counts[MoadianDocument::STATUS_QUEUED] ?? 0),
                    'sent' => (int) ($counts[MoadianDocument::STATUS_SENT] ?? 0),
                    'success' => (int) ($counts[MoadianDocument::STATUS_SUCCESS] ?? 0),
                    'failed' => (int) ($counts[MoadianDocument::STATUS_FAILED] ?? 0),
                ],
                'products_missing_sstid' => $missingSstid,
            ],
        ], 200);
    }

    /** GET /api/accounting/moadian/settings */
    public function showSettings(Request $request)
    {
        $atelierId = $this->readAtelier($request);

        return response(['data' => $this->settingsPayload(MoadianShopSetting::forAtelier($atelierId))], 200);
    }

    /** PUT /api/accounting/moadian/settings */
    public function updateSettings(Request $request)
    {
        $atelierId = $this->writeAtelier($request);

        $fields = $request->validate([
            'enabled' => 'sometimes|boolean',
            'environment' => ['sometimes', Rule::in([MoadianShopSetting::ENV_SANDBOX, MoadianShopSetting::ENV_PRODUCTION])],
            'connection_mode' => ['sometimes', Rule::in([MoadianShopSetting::MODE_SELF_TSP, MoadianShopSetting::MODE_TSP])],
            'memory_id' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]{6}$/'],
            'private_key' => 'sometimes|nullable|string|max:10000',
            'certificate' => 'sometimes|nullable|string|max:20000',
            'tsp_provider' => 'sometimes|nullable|string|max:100',
            'price_includes_vat' => 'sometimes|boolean',
            'default_invoice_type' => ['sometimes', Rule::in([1, 2])],
            'auto_type1_with_buyer' => 'sometimes|boolean',
            'default_vat_rate' => 'sometimes|numeric|min:0|max:100',
            'default_sstid' => ['sometimes', 'nullable', 'regex:/^\d{13}$/'],
            'default_sstt' => 'sometimes|nullable|string|max:200',
            'unit_code_piece' => 'sometimes|nullable|string|max:10',
            'unit_code_kg' => 'sometimes|nullable|string|max:10',
            'unit_code_meter' => 'sometimes|nullable|string|max:10',
            'late_threshold_days' => 'sometimes|nullable|integer|min:1|max:365',
        ], [
            'memory_id.regex' => 'شناسه یکتای حافظه مالیاتی باید ۶ کاراکتر (حرف انگلیسی/عدد) باشد.',
            'default_sstid.regex' => 'شناسه کالا/خدمت باید ۱۳ رقم باشد.',
        ]);

        $settings = MoadianShopSetting::forAtelier($atelierId);

        if (array_key_exists('private_key', $fields)) {
            $key = trim((string) $fields['private_key']);
            unset($fields['private_key']);
            if ($key !== '') {
                if (MoadianCrypto::publicKeyFromPrivate(MoadianCrypto::normalizePrivateKeyPem($key)) === null) {
                    return response()->json(['message' => 'کلید خصوصی معتبر نیست.'], 422);
                }
                $settings->private_key = $key;
            }
        }
        if (array_key_exists('certificate', $fields)) {
            $cert = trim((string) $fields['certificate']);
            if ($cert !== '' && ! $this->certificateInfo($cert)) {
                return response()->json(['message' => 'گواهی امضا معتبر نیست (فایل PEM یا متن base64 گواهی را وارد کنید).'], 422);
            }
            $fields['certificate'] = $cert !== '' ? $cert : null;
        }
        if (isset($fields['memory_id'])) {
            $fields['memory_id'] = strtoupper(trim((string) $fields['memory_id']));
        }

        $settings->fill($fields);
        $settings->atelier_id = $atelierId;

        if ($settings->enabled && $settings->start_purchase_id === null) {
            $settings->start_purchase_id = (int) DB::table('purchases')->where('atelier_id', $atelierId)->max('id');
            $settings->started_at = now();
        }
        $settings->paused_reason = null;
        $settings->save();

        return response([
            'message' => 'تنظیمات سامانه مؤدیان ذخیره شد.',
            'data' => $this->settingsPayload($settings->fresh()),
        ], 200);
    }

    /** POST /api/accounting/moadian/generate-key */
    public function generateKey(Request $request)
    {
        $atelierId = $this->writeAtelier($request);
        $fields = $request->validate([
            'common_name' => 'required|string|max:120',
            'serial_number' => 'required|string|max:20',
            'organization' => 'nullable|string|max:120',
            'replace' => 'sometimes|boolean',
        ]);

        $settings = MoadianShopSetting::forAtelier($atelierId);
        if ($settings->hasPrivateKey() && ! ($fields['replace'] ?? false)) {
            return response()->json(['message' => 'کلید خصوصی از قبل ثبت شده است. برای جایگزینی گزینهٔ «جایگزینی کلید» را تأیید کنید.'], 409);
        }

        try {
            $generated = MoadianCrypto::generateKeyAndCsr($fields);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $settings->atelier_id = $atelierId;
        $settings->private_key = $generated['private_key'];
        $settings->certificate = null;
        $settings->last_verified_at = null;
        $settings->save();

        return response([
            'message' => 'کلید ساخته شد. کلید عمومی را در کارپوشهٔ سامانه مؤدیان ثبت و گواهی دریافتی را اینجا وارد کنید.',
            'data' => [
                'public_key' => $generated['public_key'],
                'csr' => $generated['csr'],
            ],
        ], 200);
    }

    /** POST /api/accounting/moadian/test-connection */
    public function testConnection(Request $request)
    {
        $atelierId = $this->writeAtelier($request);
        $settings = MoadianShopSetting::forAtelier($atelierId);
        if (! $settings->exists) {
            return response()->json(['message' => 'ابتدا تنظیمات را ذخیره کنید.'], 422);
        }

        try {
            $info = MoadianRunner::gateway($settings)->fiscalInformation();
        } catch (MoadianConfigException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'اتصال ناموفق: '.$e->getMessage()], 502);
        }

        $settings->forceFill(['last_verified_at' => now(), 'paused_reason' => null])->save();

        return response([
            'message' => 'اتصال به سامانه مؤدیان برقرار است.',
            'data' => $info,
        ], 200);
    }

    /** POST /api/accounting/moadian/run */
    public function runNow(Request $request)
    {
        $atelierId = $this->writeAtelier($request);
        $settings = MoadianShopSetting::forAtelier($atelierId);
        if (! $settings->exists || ! $settings->enabled) {
            return response()->json(['message' => 'ارسال به سامانه مؤدیان فعال نیست.'], 422);
        }
        if (trim((string) $settings->paused_reason) !== '') {
            $settings->forceFill(['paused_reason' => null])->save();
        }

        $report = MoadianRunner::runShop($settings);

        return response([
            'message' => $report['ok'] ? ($report['message'] ?? 'پردازش انجام شد.') : ($report['message'] ?? 'پردازش ناموفق بود.'),
            'data' => $report,
        ], $report['ok'] ? 200 : 422);
    }

    /** GET /api/accounting/moadian/documents */
    public function documents(Request $request)
    {
        $atelierId = $this->readAtelier($request);

        $query = MoadianDocument::query()->where('atelier_id', $atelierId)->orderByDesc('id');
        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }
        if ($request->filled('subject')) {
            $query->where('subject', (int) $request->input('subject'));
        }
        if ($request->filled('purchase_id')) {
            $query->where('purchase_id', (int) $request->input('purchase_id'));
        }
        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('taxid', 'like', '%'.$s.'%')
                    ->orWhere('reference_number', 'like', '%'.$s.'%');
                if (ctype_digit($s)) {
                    $q->orWhere('purchase_id', (int) $s);
                }
            });
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn (MoadianDocument $d) => $d->toApiArray());

        return response($page, 200);
    }

    /** GET /api/accounting/moadian/documents/{id} */
    public function showDocument(Request $request, int $id)
    {
        $atelierId = $this->readAtelier($request);
        $doc = MoadianDocument::query()->where('atelier_id', $atelierId)->with('items')->findOrFail($id);

        return response(['data' => $doc->toApiArray(true)], 200);
    }

    /** POST /api/accounting/moadian/documents/{id}/retry */
    public function retryDocument(Request $request, int $id)
    {
        $atelierId = $this->writeAtelier($request);
        $settings = MoadianShopSetting::forAtelier($atelierId);
        $doc = MoadianDocument::query()->where('atelier_id', $atelierId)->findOrFail($id);

        try {
            $doc = (new MoadianDocumentService($settings))->retry($doc, $this->actorId($request));
        } catch (\DomainException|MoadianConfigException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => $doc->status === MoadianDocument::STATUS_DISCARDED
                ? 'صورتحساب کنار گذاشته شد و در دور بعد از روی وضعیت فعلی فروش دوباره ساخته می‌شود.'
                : ($doc->status === MoadianDocument::STATUS_FAILED ? 'صورتحساب هنوز خطا دارد؛ خطاها را برطرف کنید.' : 'صورتحساب در صف ارسال قرار گرفت.'),
            'data' => $doc->toApiArray(),
        ], 200);
    }

    /** POST /api/accounting/moadian/documents/retry-failed */
    public function retryFailed(Request $request)
    {
        $atelierId = $this->writeAtelier($request);
        $settings = MoadianShopSetting::forAtelier($atelierId);
        $service = new MoadianDocumentService($settings);

        $queued = 0;
        $stillFailed = 0;
        $docs = MoadianDocument::query()
            ->where('atelier_id', $atelierId)
            ->where('status', MoadianDocument::STATUS_FAILED)
            ->orderBy('id')
            ->limit(100)
            ->get();
        foreach ($docs as $doc) {
            try {
                $doc = $service->retry($doc, $this->actorId($request));
                $doc->status === MoadianDocument::STATUS_FAILED ? $stillFailed++ : $queued++;
            } catch (MoadianConfigException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            } catch (\Throwable $e) {
                $stillFailed++;
                report($e);
            }
        }

        return response([
            'message' => "{$queued} صورتحساب دوباره در صف قرار گرفت".($stillFailed ? "؛ {$stillFailed} مورد هنوز خطا دارد." : '.'),
        ], 200);
    }

    /** GET /api/accounting/moadian/stuff-ids */
    public function stuffIds(Request $request)
    {
        $atelierId = $this->readAtelier($request);
        $rows = MoadianStuffId::query()->where('atelier_id', $atelierId)->orderBy('title')->get();

        return response(['data' => $rows], 200);
    }

    /** POST /api/accounting/moadian/stuff-ids */
    public function storeStuffId(Request $request)
    {
        $atelierId = $this->writeAtelier($request);
        $fields = $this->validateStuff($request, $atelierId, null);
        $row = MoadianStuffId::query()->create($fields + ['atelier_id' => $atelierId]);

        return response(['message' => 'شناسه کالا/خدمت ثبت شد.', 'data' => $row], 201);
    }

    /** PUT /api/accounting/moadian/stuff-ids/{id} */
    public function updateStuffId(Request $request, int $id)
    {
        $atelierId = $this->writeAtelier($request);
        $row = MoadianStuffId::query()->where('atelier_id', $atelierId)->findOrFail($id);
        $fields = $this->validateStuff($request, $atelierId, $row->id);
        $oldSstid = $row->sstid;
        $row->fill($fields)->save();

        if ($oldSstid !== $row->sstid && Schema::hasColumn('products', 'moadian_sstid')) {
            DB::table('products')
                ->where('atelier_id', $atelierId)
                ->where('moadian_sstid', $oldSstid)
                ->update(['moadian_sstid' => $row->sstid]);
        }

        return response(['message' => 'شناسه کالا/خدمت ویرایش شد.', 'data' => $row], 200);
    }

    /** DELETE /api/accounting/moadian/stuff-ids/{id} */
    public function destroyStuffId(Request $request, int $id)
    {
        $atelierId = $this->writeAtelier($request);
        $row = MoadianStuffId::query()->where('atelier_id', $atelierId)->findOrFail($id);

        $used = Schema::hasColumn('products', 'moadian_sstid')
            ? DB::table('products')->where('atelier_id', $atelierId)->whereNull('deleted_at')->where('moadian_sstid', $row->sstid)->count()
            : 0;
        if ($used > 0) {
            return response()->json(['message' => "این شناسه به {$used} کالا اختصاص داده شده است؛ ابتدا شناسهٔ آن کالاها را تغییر دهید."], 422);
        }
        $row->delete();

        return response(['message' => 'شناسه کالا/خدمت حذف شد.'], 200);
    }

    /** GET /api/accounting/moadian/products */
    public function products(Request $request)
    {
        $atelierId = $this->readAtelier($request);
        if (! Schema::hasColumn('products', 'moadian_sstid')) {
            return response()->json(['message' => 'ستون moadian_sstid وجود ندارد. migration یا فایل SQL را اجرا کنید.'], 422);
        }

        $query = DB::table('products')
            ->where('atelier_id', $atelierId)
            ->whereNull('deleted_at')
            ->select('id', 'name', 'barcode', 'unit_type', 'sale_price', 'moadian_sstid')
            ->orderBy('name');
        if ($request->boolean('missing')) {
            $query->where(function ($q) {
                $q->whereNull('moadian_sstid')->orWhere('moadian_sstid', '');
            });
        }
        if ($request->filled('sstid')) {
            $query->where('moadian_sstid', (string) $request->input('sstid'));
        }
        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', '%'.$s.'%')->orWhere('barcode', 'like', '%'.$s.'%');
            });
        }

        $perPage = max(1, min(200, (int) $request->input('per_page', 50)));

        return response($query->paginate($perPage), 200);
    }

    /** POST /api/accounting/moadian/products/assign */
    public function assignProducts(Request $request)
    {
        $atelierId = $this->writeAtelier($request);
        if (! Schema::hasColumn('products', 'moadian_sstid')) {
            return response()->json(['message' => 'ستون moadian_sstid وجود ندارد. migration یا فایل SQL را اجرا کنید.'], 422);
        }
        $fields = $request->validate([
            'product_ids' => 'required|array|min:1|max:500',
            'product_ids.*' => 'integer',
            'sstid' => ['nullable', 'regex:/^\d{13}$/'],
        ], ['sstid.regex' => 'شناسه کالا/خدمت باید ۱۳ رقم باشد.']);

        $sstid = $fields['sstid'] ?? null;
        if ($sstid !== null && ! MoadianStuffId::query()->where('atelier_id', $atelierId)->where('sstid', $sstid)->exists()) {
            return response()->json(['message' => 'این شناسه در فهرست شناسه‌های کالا/خدمت فروشگاه ثبت نشده است.'], 422);
        }

        $updated = DB::table('products')
            ->where('atelier_id', $atelierId)
            ->whereIn('id', $fields['product_ids'])
            ->update(['moadian_sstid' => $sstid]);

        return response(['message' => "شناسه برای {$updated} کالا ذخیره شد.", 'updated' => $updated], 200);
    }

    private function validateStuff(Request $request, int $atelierId, ?int $ignoreId): array
    {
        return $request->validate([
            'sstid' => [
                'required',
                'regex:/^\d{13}$/',
                Rule::unique('moadian_stuff_ids', 'sstid')
                    ->where(fn ($q) => $q->where('atelier_id', $atelierId))
                    ->ignore($ignoreId),
            ],
            'title' => 'required|string|max:200',
            'vat_rate' => 'required|numeric|min:0|max:100',
            'other_tax_rate' => 'nullable|numeric|min:0|max:100',
            'other_tax_subject' => 'nullable|string|max:200',
            'unit_code' => 'nullable|string|max:10',
        ], [
            'sstid.regex' => 'شناسه کالا/خدمت باید ۱۳ رقم باشد.',
            'sstid.unique' => 'این شناسه قبلاً ثبت شده است.',
        ]);
    }

    private function settingsPayload(MoadianShopSetting $settings): array
    {
        $seller = FormalInvoiceSellerProfile::query()->where('atelier_id', $settings->atelier_id)->first();
        $certificate = trim((string) $settings->certificate);
        $publicKey = null;
        if ($settings->hasPrivateKey()) {
            try {
                $publicKey = MoadianCrypto::publicKeyFromPrivate(MoadianCrypto::normalizePrivateKeyPem((string) $settings->private_key));
            } catch (\Throwable $e) {
                $publicKey = null;
            }
        }

        return [
            'exists' => (bool) $settings->exists,
            'enabled' => (bool) $settings->enabled,
            'environment' => $settings->environment ?? MoadianShopSetting::ENV_SANDBOX,
            'connection_mode' => $settings->connection_mode ?? MoadianShopSetting::MODE_SELF_TSP,
            'memory_id' => $settings->memory_id,
            'has_private_key' => $settings->hasPrivateKey(),
            'public_key' => $publicKey,
            'certificate' => $certificate !== '' ? $certificate : null,
            'certificate_info' => $certificate !== '' ? $this->certificateInfo($certificate) : null,
            'tsp_provider' => $settings->tsp_provider,
            'price_includes_vat' => $settings->price_includes_vat ?? true,
            'default_invoice_type' => (int) ($settings->default_invoice_type ?? 2),
            'auto_type1_with_buyer' => $settings->auto_type1_with_buyer ?? true,
            'default_vat_rate' => (float) ($settings->default_vat_rate ?? 10),
            'default_sstid' => $settings->default_sstid,
            'default_sstt' => $settings->default_sstt,
            'unit_code_piece' => $settings->unit_code_piece,
            'unit_code_kg' => $settings->unit_code_kg,
            'unit_code_meter' => $settings->unit_code_meter,
            'late_threshold_days' => $settings->late_threshold_days,
            'start_purchase_id' => $settings->start_purchase_id,
            'started_at' => $this->jalali($settings->started_at),
            'paused_reason' => $settings->paused_reason,
            'last_verified_at' => $this->jalali($settings->last_verified_at),
            'last_run_at' => $this->jalali($settings->last_run_at),
            'seller' => [
                'legal_name' => $seller?->legal_name,
                'economic_code' => $seller?->economic_code,
                'national_id' => $seller?->national_id,
                'postal_code' => $seller?->postal_code,
            ],
            'problems' => $settings->exists ? $settings->readinessProblems() : [],
        ];
    }

    private function certificateInfo(string $certificate): ?array
    {
        $pem = strpos($certificate, '-----BEGIN') !== false
            ? $certificate
            : "-----BEGIN CERTIFICATE-----\n".chunk_split(preg_replace('/\s+/', '', $certificate), 64, "\n").'-----END CERTIFICATE-----';
        $parsed = @openssl_x509_parse($pem);
        if (! is_array($parsed)) {
            return null;
        }

        $validTo = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;

        return [
            'subject' => $parsed['subject']['CN'] ?? ($parsed['name'] ?? null),
            'serial_number' => $parsed['subject']['serialNumber'] ?? null,
            'valid_to' => $validTo ? $this->jalali(\Carbon\Carbon::createFromTimestamp($validTo)) : null,
            'expired' => $validTo ? $validTo < time() : false,
        ];
    }

    private function jalali($date): ?string
    {
        if (! $date) {
            return null;
        }
        $carbon = $date instanceof \Carbon\Carbon ? $date : \Carbon\Carbon::parse($date);

        return \Morilog\Jalali\Jalalian::fromCarbon($carbon->setTimezone('Asia/Tehran'))->format('Y-m-d H:i');
    }

    private function readAtelier(Request $request): int
    {
        $atelierId = $this->assertShopFeature($request, ShopFeatureFlags::ACCOUNTING, self::FEATURE_MESSAGE);
        $this->assertTablesReady();

        return $atelierId;
    }

    private function writeAtelier(Request $request): int
    {
        $atelierId = $this->readAtelier($request);
        if ($this->staffShopAtelierId($request) === null) {
            abort(response()->json(['message' => 'این عملیات فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.'], 422));
        }

        return $atelierId;
    }

    private function actorId(Request $request): ?int
    {
        $actor = $this->shopRequestActor($request);

        return $actor ? (int) $actor->getAuthIdentifier() : null;
    }

    private function assertTablesReady(): void
    {
        if (! MoadianShopSetting::tableReady() || ! Schema::hasTable('moadian_stuff_ids')) {
            abort(response()->json([
                'message' => 'جدول‌های سامانه مؤدیان وجود ندارد. migration یا فایل SQL (database/sql/create_moadian_tables_manual.sql) را اجرا کنید.',
            ], 422));
        }
    }
}
