<?php

namespace App\Http\Controllers\Repair;

use App\Http\Controllers\Controller;
use App\Models\RepairService;
use App\Models\RepairSetting;
use App\Models\RepairUser;
use App\Services\Repair\RepairNotifier;
use App\Services\Repair\RepairOtp;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RepairAuthController extends Controller
{
    private const TECH_REGISTRATION_PREFIX = 'repair_tech_registration:';

    public function __construct(protected RepairOtp $otp)
    {
    }

    public function config()
    {
        $this->assertSchema();
        $values = RepairSetting::allValues();
        $services = $this->activeServices();

        return response([
            'brand_name' => $values['brand_name'],
            'support_phone' => $values['support_phone'],
            'services' => $services,
            'categories' => array_column($services, 'name'),
            'online_payment_enabled' => $values['online_payment_enabled'] === '1',
            'card_payment_enabled' => $values['card_payment_enabled'] === '1',
            'location_mode' => RepairSetting::locationMode($values),
            'map_provider' => RepairSetting::mapProvider($values),
            'neshan_map_key' => (string) config('repair.neshan_map_key'),
        ]);
    }

    /**
     * تبدیل مختصات به آدرس با نشان (کلید سرویس سمت سرور می‌ماند) و در نبودش OpenStreetMap.
     */
    public function reverseGeocode(Request $request)
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:24,40',
            'lng' => 'required|numeric|between:44,64',
        ]);
        $lat = round((float) $data['lat'], 5);
        $lng = round((float) $data['lng'], 5);
        $useNeshan = RepairSetting::mapProvider() === 'neshan' && (string) config('repair.neshan_service_key') !== '';

        $address = Cache::remember('repair_reverse:'.$lat.','.$lng, now()->addDays(7), function () use ($useNeshan, $lat, $lng) {
            return ($useNeshan ? $this->neshanReverse($lat, $lng) : null) ?? $this->osmReverse($lat, $lng);
        });

        return response(['address' => $address]);
    }

    private function neshanReverse(float $lat, float $lng): ?string
    {
        try {
            $res = Http::withHeaders(['Api-Key' => (string) config('repair.neshan_service_key')])
                ->timeout(8)
                ->get('https://api.neshan.org/v5/reverse', ['lat' => $lat, 'lng' => $lng]);
        } catch (\Throwable $e) {
            return null;
        }
        $address = $res->successful() ? $res->json('formatted_address') : null;

        return is_string($address) && $address !== '' ? $address : null;
    }

    /** Nominatim (OpenStreetMap): رایگان، حداکثر ۱ درخواست در ثانیه، User-Agent الزامی. */
    private function osmReverse(float $lat, float $lng): ?string
    {
        try {
            $res = Http::withHeaders([
                'User-Agent' => 'RepairApp/1.0 ('.(config('repair.frontend_url') ?: config('app.url')).')',
                'Accept-Language' => 'fa',
            ])
                ->timeout(8)
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'format' => 'jsonv2',
                    'lat' => $lat,
                    'lon' => $lng,
                    'zoom' => 18,
                    'addressdetails' => 1,
                    'accept-language' => 'fa',
                ]);
        } catch (\Throwable $e) {
            return null;
        }
        if (! $res->successful()) {
            return null;
        }

        $parts = (array) $res->json('address', []);
        $pick = function (array $keys) use ($parts): ?string {
            foreach ($keys as $key) {
                if (! empty($parts[$key]) && is_string($parts[$key])) {
                    return trim($parts[$key]);
                }
            }

            return null;
        };
        $segments = array_values(array_unique(array_filter([
            $pick(['city', 'town', 'village', 'county']),
            $pick(['suburb', 'neighbourhood', 'quarter', 'city_district']),
            $pick(['road', 'pedestrian', 'residential']),
        ])));
        if ($house = $pick(['house_number'])) {
            $segments[] = 'پلاک '.$house;
        }
        if ($segments !== []) {
            return implode('، ', $segments);
        }

        $display = $res->json('display_name');

        return is_string($display) && $display !== '' ? $display : null;
    }

    public function sendCode(Request $request)
    {
        $this->assertSchema();
        $phone = $this->validatedPhone($request);

        $existing = RepairUser::query()->where('phone', $phone)->first();
        if ($existing && ! $existing->is_active) {
            return response()->json(['message' => 'حساب شما غیرفعال است.'], 403);
        }

        $wait = $this->otp->send($phone);
        if ($wait > 0) {
            return response([
                'message' => 'لطفاً قبل از درخواست مجدد چند لحظه صبر کنید.',
                'retry_after_seconds' => $wait,
            ], 429);
        }

        return response([
            'message' => 'کد ورود به شمارهٔ شما ارسال شد.',
            'is_new' => $existing === null && ! $this->isAdminPhone($phone),
            'retry_after_seconds' => RepairOtp::RESEND_SECONDS,
        ], 201);
    }

    public function verify(Request $request)
    {
        $this->assertSchema();
        $data = $request->validate([
            'code' => 'required|string|max:10',
            'name' => 'nullable|string|max:150',
        ]);
        $phone = $this->validatedPhone($request);

        $error = $this->otp->verify($phone, $data['code']);
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        $user = RepairUser::query()->where('phone', $phone)->first();
        if ($user && ! $user->is_active) {
            return response()->json(['message' => 'حساب شما غیرفعال است.'], 403);
        }
        if ($user && $user->isTechnician() && ! $user->isApproved()) {
            return response()->json(['message' => $this->approvalMessage($user)], 403);
        }

        if ($this->isAdminPhone($phone)) {
            if (! $user) {
                $user = RepairUser::create([
                    'role' => RepairUser::ROLE_ADMIN,
                    'phone' => $phone,
                    'name' => $data['name'] ?? 'مدیر',
                ]);
            } elseif (! $user->isAdmin()) {
                $user->update(['role' => RepairUser::ROLE_ADMIN, 'approval_status' => RepairUser::APPROVAL_APPROVED]);
            }
        } elseif (! $user) {
            $user = RepairUser::create([
                'role' => RepairUser::ROLE_CUSTOMER,
                'phone' => $phone,
                'name' => $data['name'] ?? null,
            ]);
        } elseif (! $user->name && ! empty($data['name'])) {
            $user->name = $data['name'];
        }

        return $this->issueToken($user);
    }

    /**
     * ورود اپ تعمیرکاران: تعمیرکار تأییدشده توکن می‌گیرد، شمارهٔ جدید به فرم ثبت‌نام می‌رود.
     */
    public function techVerify(Request $request)
    {
        $this->assertSchema();
        $data = $request->validate(['code' => 'required|string|max:10']);
        $phone = $this->validatedPhone($request);

        $error = $this->otp->verify($phone, $data['code']);
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        $user = RepairUser::query()->where('phone', $phone)->first();
        if ($this->isAdminPhone($phone) || ($user && $user->isAdmin())) {
            return response()->json(['message' => 'این شماره مدیر است؛ از صفحهٔ ورود اصلی وارد شوید.'], 422);
        }
        if ($user && ! $user->is_active) {
            return response()->json(['message' => 'حساب شما غیرفعال است.'], 403);
        }

        if ($user && $user->isTechnician()) {
            if (! $user->isApproved()) {
                return response([
                    'status' => $user->approval_status,
                    'message' => $this->approvalMessage($user),
                ]);
            }

            return $this->issueToken($user);
        }

        if ($user && $user->customerRequests()->exists()) {
            return response()->json([
                'message' => 'این شماره به‌عنوان مشتری درخواست ثبت کرده است؛ برای ثبت‌نام تعمیرکار شمارهٔ دیگری وارد کنید.',
            ], 422);
        }

        $token = Str::random(48);
        Cache::put(self::TECH_REGISTRATION_PREFIX.$token, $phone, now()->addMinutes(30));

        return response([
            'status' => 'needs_registration',
            'registration_token' => $token,
            'phone' => $phone,
            'services' => $this->activeServices(),
        ]);
    }

    public function techRegister(Request $request, RepairNotifier $notifier)
    {
        $this->assertSchema();
        $data = $request->validate([
            'registration_token' => 'required|string|max:100',
            'name' => 'required|string|max:150',
            'specialty' => 'nullable|string|max:255',
            'service_ids' => 'required|array|min:1',
            'service_ids.*' => 'integer',
            'card_number' => 'nullable|string|max:32',
            'address' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:2000',
        ], [
            'service_ids.required' => 'حداقل یک نوع خدمت را انتخاب کنید.',
            'service_ids.min' => 'حداقل یک نوع خدمت را انتخاب کنید.',
        ]);

        $phone = Cache::get(self::TECH_REGISTRATION_PREFIX.$data['registration_token']);
        if (! is_string($phone) || $phone === '') {
            return response()->json(['message' => 'زمان ثبت‌نام تمام شده است؛ دوباره کد بگیرید.'], 422);
        }

        $serviceIds = RepairService::query()
            ->where('is_active', true)
            ->whereIn('id', $data['service_ids'])
            ->pluck('id')
            ->all();
        if ($serviceIds === []) {
            return response()->json(['message' => 'حداقل یک نوع خدمت را انتخاب کنید.'], 422);
        }

        $user = RepairUser::query()->where('phone', $phone)->first();
        if ($user && ! $user->isCustomer()) {
            return response()->json(['message' => 'این شماره قبلاً ثبت شده است.'], 422);
        }
        if ($user && $user->customerRequests()->exists()) {
            return response()->json(['message' => 'این شماره به‌عنوان مشتری درخواست ثبت کرده است.'], 422);
        }

        $attributes = [
            'role' => RepairUser::ROLE_TECHNICIAN,
            'approval_status' => RepairUser::APPROVAL_PENDING,
            'approval_note' => null,
            'name' => $data['name'],
            'phone' => $phone,
            'specialty' => $data['specialty'] ?? null,
            'card_number' => $data['card_number'] ?? null,
            'address' => $data['address'] ?? null,
            'notes' => $data['notes'] ?? null,
            'labor_share_percent' => (float) RepairSetting::value('default_labor_share_percent'),
            'is_active' => true,
        ];
        if ($user) {
            $user->tokens()->delete();
            $user->update($attributes);
        } else {
            $user = RepairUser::create($attributes);
        }
        $user->services()->sync($serviceIds);
        Cache::forget(self::TECH_REGISTRATION_PREFIX.$data['registration_token']);

        $notifier->technicianRegistered($user);

        return response([
            'status' => RepairUser::APPROVAL_PENDING,
            'message' => 'ثبت‌نام شما انجام شد و پس از تأیید مدیر می‌توانید وارد شوید.',
        ], 201);
    }

    public function me(Request $request)
    {
        /** @var RepairUser $user */
        $user = $request->user();

        return response(['user' => $user->toSessionArray()]);
    }

    public function updateProfile(Request $request)
    {
        /** @var RepairUser $user */
        $user = $request->user();
        $rules = [
            'name' => 'sometimes|required|string|max:150',
            'address' => 'nullable|string|max:1000',
        ];
        if ($user->role === RepairUser::ROLE_TECHNICIAN) {
            $rules['specialty'] = 'nullable|string|max:255';
            $rules['card_number'] = 'nullable|string|max:40';
        }
        $user->update($request->validate($rules));

        return response(['user' => $user->fresh()->toSessionArray()]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response(['message' => 'خارج شدید.']);
    }

    private function issueToken(RepairUser $user)
    {
        $user->last_login_at = now();
        $user->save();

        $token = $user->createToken('repair-'.$user->role)->plainTextToken;

        return response([
            'status' => 'ok',
            'token' => $token,
            'user' => $user->toSessionArray(),
        ], 201);
    }

    private function approvalMessage(RepairUser $user): string
    {
        if ($user->approval_status === RepairUser::APPROVAL_REJECTED) {
            return 'ثبت‌نام شما تأیید نشد.'.($user->approval_note ? ' '.$user->approval_note : '');
        }

        return 'ثبت‌نام شما انجام شده و در انتظار تأیید مدیر است.';
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function activeServices(): array
    {
        if (! Schema::hasTable('repair_services')) {
            return array_map(fn ($name) => ['id' => 0, 'name' => $name], RepairSetting::categories());
        }

        return RepairService::query()->where('is_active', true)->ordered()->get(['id', 'name'])
            ->map(fn (RepairService $s) => ['id' => (int) $s->id, 'name' => $s->name])
            ->all();
    }

    private function validatedPhone(Request $request): string
    {
        $data = $request->validate(['phone' => 'required|string|max:20']);
        $phone = PhoneTools::normalizeIranPhone($data['phone']);
        if (! PhoneTools::isValidIranMobile($phone)) {
            abort(response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422));
        }

        return $phone;
    }

    private function isAdminPhone(string $phone): bool
    {
        foreach (config('repair.admin_phones', []) as $adminPhone) {
            if (PhoneTools::normalizeIranPhone($adminPhone) === $phone) {
                return true;
            }
        }

        return false;
    }

    private function assertSchema(): void
    {
        if (! Schema::hasTable('repair_users')) {
            abort(response()->json([
                'message' => 'جداول سامانهٔ تعمیرکار ساخته نشده‌اند. migration یا database/sql/create_repair_tables_manual.sql را اجرا کنید.',
            ], 503));
        }
    }
}
