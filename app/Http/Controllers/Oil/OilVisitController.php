<?php

namespace App\Http\Controllers\Oil;

use App\Exceptions\InsufficientShopSmsQuotaException;
use App\Http\Controllers\Controller;
use App\Models\OilProduct;
use App\Models\OilVisit;
use App\Models\OilVisitItem;
use App\Services\OilPublicHistoryService;
use App\Services\OilVisitSaleService;
use App\Support\ProjectType;
use App\Tools\PhoneTools;
use App\Tools\PlateTools;
use App\Tools\SmsTools;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OilVisitController extends Controller
{
    public function customers(Request $request)
    {
        $atelierId = $this->oilAtelierId($request);
        $q = trim((string) $request->input('q', ''));
        $perPage = max(1, min(50, (int) $request->input('per_page', 30)));

        $latestIds = OilVisit::query()
            ->selectRaw('MAX(id)')
            ->where('atelier_id', $atelierId)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('plate', 'like', '%'.$q.'%')
                        ->orWhere('plate_display', 'like', '%'.$q.'%')
                        ->orWhere('phone', 'like', '%'.$q.'%');
                });
            })
            ->groupBy('plate');

        $rows = OilVisit::query()
            ->withItems()
            ->where('atelier_id', $atelierId)
            ->whereIn('id', $latestIds)
            ->orderByDesc('id')
            ->paginate($perPage);

        $plates = collect($rows->items())->pluck('plate')->all();
        $counts = OilVisit::query()
            ->where('atelier_id', $atelierId)
            ->whereIn('plate', $plates)
            ->select('plate', DB::raw('COUNT(*) as visit_count'))
            ->groupBy('plate')
            ->pluck('visit_count', 'plate');

        $rows->getCollection()->transform(function (OilVisit $visit) use ($counts) {
            $payload = $visit->toApiArray();
            $payload['visit_count'] = (int) ($counts[$visit->plate] ?? 1);

            return $payload;
        });

        return response($rows);
    }

    public function showCustomer(Request $request, string $plate)
    {
        $atelierId = $this->oilAtelierId($request);
        $parsed = PlateTools::parse(urldecode($plate));
        $compact = $parsed['compact'] ?? trim($plate);

        $visits = OilVisit::query()
            ->withItems()
            ->where('atelier_id', $atelierId)
            ->where('plate', $compact)
            ->orderByDesc('id')
            ->get();

        if ($visits->isEmpty()) {
            return response()->json(['message' => 'مشتری یافت نشد.'], 404);
        }

        $latest = $visits->first();

        return response()->json([
            'customer' => array_merge($latest->toApiArray(), [
                'visit_count' => $visits->count(),
            ]),
            'visits' => $visits->map(fn (OilVisit $v) => $v->toApiArray())->values(),
        ]);
    }

    public function lookup(Request $request)
    {
        $atelierId = $this->oilAtelierId($request);
        $plateRaw = $request->input('plate');
        $phoneRaw = $request->input('phone');

        $query = OilVisit::query()->withItems()->where('atelier_id', $atelierId);

        if ($plateRaw) {
            $parsed = PlateTools::parse($plateRaw);
            if ($parsed) {
                $query->where('plate', $parsed['compact']);
            } else {
                $query->where(function ($w) use ($plateRaw) {
                    $w->where('plate', 'like', '%'.$plateRaw.'%')
                        ->orWhere('plate_display', 'like', '%'.$plateRaw.'%');
                });
            }
        } elseif ($phoneRaw) {
            $phone = PhoneTools::normalizeIranPhone($phoneRaw);
            if (! $phone) {
                return response()->json(['found' => false]);
            }
            $query->where('phone', $phone);
        } else {
            return response()->json(['message' => 'پلاک یا تلفن بفرستید.'], 422);
        }

        $last = $query->orderByDesc('id')->first();
        if (! $last) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'visit' => $last->toApiArray(),
        ]);
    }

    public function store(Request $request)
    {
        $atelierId = $this->oilAtelierId($request);
        $user = $request->user();
        $atelier = $user->atelier;

        $fields = $request->validate([
            'client_id' => 'nullable|string|max:64',
            'occurred_at' => 'nullable|date',
            'plate' => 'nullable|string|max:64',
            'serial' => 'nullable|string|max:4',
            'letter' => 'nullable|string|max:8',
            'middle' => 'nullable|string|max:4',
            'province' => 'nullable|string|max:4',
            'phone' => 'required|string|max:15',
            'km' => 'required|integer|min:0|max:9999999',
            'next_km' => 'nullable|integer|min:1|max:9999999',
            'notes' => 'nullable|string|max:1000',
            'oil_product_id' => 'nullable|integer|min:1',
            'gearbox_oil_product_id' => 'nullable|integer|min:1',
            'air_filter_product_id' => 'nullable|integer|min:1',
            'oil_filter_product_id' => 'nullable|integer|min:1',
        ]);

        $parsed = PlateTools::parse($fields['plate'] ?? '')
            ?: PlateTools::fromParts(
                (string) ($fields['serial'] ?? ''),
                (string) ($fields['letter'] ?? ''),
                (string) ($fields['middle'] ?? ''),
                (string) ($fields['province'] ?? '')
            );

        if (! $parsed) {
            return response()->json(['message' => 'پلاک معتبر نیست. دو رقم، حرف، سه رقم و کد استان را وارد کنید.'], 422);
        }

        $phone = PhoneTools::normalizeIranPhone($fields['phone']);
        if (! PhoneTools::isValidIranMobile($phone)) {
            return response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422);
        }

        $clientId = $this->normalizeClientId($fields['client_id'] ?? null);
        if ($clientId !== null && ! Schema::hasColumn('oil_visits', 'client_id')) {
            return response()->json([
                'message' => 'ستون client_id هنوز ساخته نشده. فایل database/sql/add_oil_visits_client_id_manual.sql را اجرا کنید.',
            ], 422);
        }
        if ($clientId !== null) {
            $existing = $this->findVisitByClientId($atelierId, $clientId);
            if ($existing) {
                return $this->storeVisitResponse($existing, true);
            }
        }

        $km = (int) $fields['km'];
        $interval = $atelier ? $atelier->oilIntervalKm() : 5000;
        $nextKm = isset($fields['next_km']) ? (int) $fields['next_km'] : $km + $interval;
        if ($nextKm <= $km) {
            $nextKm = $km + $interval;
        }

        $shopName = $atelier ? trim((string) $atelier->name) : 'تعویض روغن';
        if ($shopName === '') {
            $shopName = 'تعویض روغن';
        }

        $message = "خوش آمدید به {$shopName}\nکیلومتر تعویض {$km}\nتعویض بعدی {$nextKm}";

        $notes = trim((string) ($fields['notes'] ?? ''));
        if ($notes !== '' && ! Schema::hasColumn('oil_visits', 'notes')) {
            return response()->json([
                'message' => 'ستون توضیحات هنوز ساخته نشده. فایل database/sql/add_oil_visits_notes_manual.sql را اجرا کنید.',
            ], 422);
        }

        $payload = [
            'atelier_id' => $atelierId,
            'created_by' => $user->id,
            'plate' => $parsed['compact'],
            'plate_display' => $parsed['display'],
            'phone' => $phone,
            'km' => $km,
            'next_km' => $nextKm,
            'sms_sent' => false,
        ];
        if (Schema::hasColumn('oil_visits', 'notes')) {
            $payload['notes'] = $notes !== '' ? $notes : null;
        }
        if ($clientId !== null) {
            $payload['client_id'] = $clientId;
        }
        $occurredAt = $this->parseOccurredAt($fields['occurred_at'] ?? null);
        if ($occurredAt) {
            $payload['created_at'] = $occurredAt;
            $payload['updated_at'] = $occurredAt;
        }

        try {
            $visit = DB::transaction(function () use ($payload, $atelierId, $fields) {
                $visit = OilVisit::create($payload);
                $this->attachVisitItems($visit, $atelierId, $fields);
                $visit->load('items');
                OilVisitSaleService::post($visit);

                return $visit;
            });
        } catch (QueryException $e) {
            if ($clientId !== null && $this->isDuplicateClientIdException($e)) {
                $existing = $this->findVisitByClientId($atelierId, $clientId);
                if ($existing) {
                    return $this->storeVisitResponse($existing, true);
                }
            }

            throw $e;
        }

        [$smsSent, $smsError] = $this->sendOilSms($phone, $message, $atelierId, 'oil_welcome');
        $historyUrl = OilPublicHistoryService::historyUrl($phone);
        [$linkSent, $linkError] = $this->sendOilSms($phone, $historyUrl, $atelierId, 'oil_history_link');
        $visit->update([
            'sms_sent' => $smsSent,
            'sms_error' => $smsError ?: $linkError,
        ]);

        return $this->storeVisitResponse($visit, false, [
            'sms_sent' => $smsSent,
            'sms_error' => $smsError,
            'link_sms_sent' => $linkSent,
            'history_url' => $historyUrl,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function storeVisitResponse(OilVisit $visit, bool $alreadyExists, array $extra = [])
    {
        $fresh = $visit->fresh() ?: $visit;
        if (Schema::hasTable('oil_visit_items')) {
            $fresh->load('items');
        }
        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'oil_visit_id')) {
            $fresh->load('purchase');
        }

        $payload = array_merge([
            'already_exists' => $alreadyExists,
            'visit' => $fresh->toApiArray(),
        ], $extra);

        if ($alreadyExists) {
            $payload['code'] = 'duplicate_client_id';
            $payload['message'] = 'این مراجعه قبلاً با همین client_id ثبت شده است.';
            $payload['sms_sent'] = (bool) $fresh->sms_sent;
            $payload['sms_error'] = $fresh->sms_error;
            $payload['link_sms_sent'] = (bool) $fresh->sms_sent;
            $payload['history_url'] = OilPublicHistoryService::historyUrl((string) $fresh->phone);

            return response()->json($payload, 200);
        }

        $smsSent = (bool) ($extra['sms_sent'] ?? false);
        $linkSent = (bool) ($extra['link_sms_sent'] ?? false);
        $okMessage = 'ثبت شد ولی پیامک ارسال نشد.';
        if ($smsSent && $linkSent) {
            $okMessage = 'ثبت شد و پیامک خوش‌آمد و لینک سابقه ارسال گردید.';
        } elseif ($smsSent) {
            $okMessage = 'ثبت شد و پیامک ارسال گردید.';
        } elseif ($linkSent) {
            $okMessage = 'ثبت شد و لینک سابقه ارسال گردید.';
        }
        $payload['message'] = $okMessage;

        return response()->json($payload, 201);
    }

    private function normalizeClientId($clientId): ?string
    {
        if ($clientId === null) {
            return null;
        }
        $clientId = trim((string) $clientId);

        return $clientId !== '' ? $clientId : null;
    }

    private function findVisitByClientId(int $atelierId, string $clientId): ?OilVisit
    {
        return OilVisit::query()
            ->withItems()
            ->where('atelier_id', $atelierId)
            ->where('client_id', $clientId)
            ->first();
    }

    private function isDuplicateClientIdException(QueryException $e): bool
    {
        $errorCode = (int) ($e->errorInfo[1] ?? 0);

        return $errorCode === 1062 || strpos(strtolower($e->getMessage()), 'duplicate') !== false;
    }

    private function parseOccurredAt($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $at = Carbon::parse($value)->timezone(config('app.timezone', 'Asia/Tehran'));
        } catch (\Throwable $e) {
            return null;
        }
        if ($at->isFuture()) {
            return now();
        }

        return $at;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function attachVisitItems(OilVisit $visit, int $atelierId, array $fields): void
    {
        $selected = [
            OilProduct::KIND_OIL => (int) ($fields['oil_product_id'] ?? 0),
            OilProduct::KIND_GEARBOX_OIL => (int) ($fields['gearbox_oil_product_id'] ?? 0),
            OilProduct::KIND_AIR_FILTER => (int) ($fields['air_filter_product_id'] ?? 0),
            OilProduct::KIND_OIL_FILTER => (int) ($fields['oil_filter_product_id'] ?? 0),
        ];
        $hasAny = collect($selected)->contains(fn ($id) => $id > 0);
        if (! $hasAny) {
            return;
        }
        if (! Schema::hasTable('oil_visit_items') || ! Schema::hasTable('oil_products')) {
            abort(response()->json([
                'message' => 'جدول محصولات هنوز ساخته نشده. فایل database/sql/create_oil_products_manual.sql را اجرا کنید.',
            ], 422));
        }

        foreach ($selected as $kind => $productId) {
            if ($productId <= 0) {
                continue;
            }
            $product = OilProduct::query()
                ->where('atelier_id', $atelierId)
                ->where('id', $productId)
                ->where('kind', $kind)
                ->first();
            if (! $product) {
                abort(response()->json(['message' => 'محصول انتخاب‌شده معتبر نیست.'], 422));
            }
            $item = [
                'oil_visit_id' => $visit->id,
                'oil_product_id' => $product->id,
                'kind' => $kind,
                'product_name' => $product->name,
            ];
            if (Schema::hasColumn('oil_visit_items', 'purchase_price')) {
                $item['purchase_price'] = round((float) $product->purchase_price, 2);
                $item['sale_price'] = round((float) $product->sale_price, 2);
            }
            OilVisitItem::create($item);
        }
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function sendOilSms(string $phone, string $message, int $atelierId, string $smsType): array
    {
        try {
            SmsTools::sendShopSms($phone, $message, null, null, $smsType, $atelierId);

            return [true, null];
        } catch (InsufficientShopSmsQuotaException $e) {
            try {
                SmsTools::sendSms($phone, $message);

                return [true, null];
            } catch (\Throwable $fallback) {
                return [false, $e->getMessage()];
            }
        } catch (\Throwable $e) {
            return [false, 'ارسال پیامک ناموفق بود.'];
        }
    }

    private function oilAtelierId(Request $request): int
    {
        if (! Schema::hasTable('oil_visits')) {
            abort(response()->json(['message' => 'جداول تعویض روغن هنوز ساخته نشده‌اند.'], 503));
        }

        $user = $request->user();
        if (! $user || ProjectType::normalize($user->project_type) !== ProjectType::OIL) {
            abort(response()->json(['message' => 'دسترسی ندارید.'], 403));
        }
        if (! $user->atelier_id) {
            abort(response()->json(['message' => 'حساب به تعویض روغنی متصل نیست.'], 422));
        }

        return $this->shopAtelierIdOrAbort($request);
    }
}
