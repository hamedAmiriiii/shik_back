<?php

namespace App\Http\Controllers\Oil;

use App\Exceptions\InsufficientShopSmsQuotaException;
use App\Http\Controllers\Controller;
use App\Models\OilVisit;
use App\Support\ProjectType;
use App\Tools\PhoneTools;
use App\Tools\PlateTools;
use App\Tools\SmsTools;
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

        $query = OilVisit::query()->where('atelier_id', $atelierId);

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
            'plate' => 'nullable|string|max:64',
            'serial' => 'nullable|string|max:4',
            'letter' => 'nullable|string|max:8',
            'middle' => 'nullable|string|max:4',
            'province' => 'nullable|string|max:4',
            'phone' => 'required|string|max:15',
            'km' => 'required|integer|min:0|max:9999999',
            'next_km' => 'nullable|integer|min:1|max:9999999',
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

        $visit = OilVisit::create([
            'atelier_id' => $atelierId,
            'created_by' => $user->id,
            'plate' => $parsed['compact'],
            'plate_display' => $parsed['display'],
            'phone' => $phone,
            'km' => $km,
            'next_km' => $nextKm,
            'sms_sent' => false,
        ]);

        [$smsSent, $smsError] = $this->sendWelcomeSms($phone, $message, $atelierId);
        $visit->update([
            'sms_sent' => $smsSent,
            'sms_error' => $smsError,
        ]);

        return response()->json([
            'message' => $smsSent ? 'ثبت شد و پیامک ارسال گردید.' : 'ثبت شد ولی پیامک ارسال نشد.',
            'visit' => $visit->fresh()->toApiArray(),
            'sms_sent' => $smsSent,
            'sms_error' => $smsError,
        ], 201);
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function sendWelcomeSms(string $phone, string $message, int $atelierId): array
    {
        try {
            SmsTools::sendShopSms($phone, $message, null, null, 'oil_welcome', $atelierId);

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
