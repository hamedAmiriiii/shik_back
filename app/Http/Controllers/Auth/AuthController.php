<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Atelier;
use App\Models\User;
use App\Tools\ImageTools;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /** تصویر JPEG خیلی کوچک (۱×۱) برای پر کردن فیلدهای تصویر اختیاری در ثبت مرحلهٔ اول فروشگاه */
    private const PLACEHOLDER_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwABmX/9k=';

    private const SHOP_REGISTRATION_OTP_PREFIX = 'shop_registration_otp:';

    private const SHOP_REGISTRATION_OTP_COOLDOWN_PREFIX = 'shop_registration_otp_sent_at:';

    /** اعتبار کد تأیید تلفن (ثبت فروشگاه) به دقیقه */
    private const SHOP_REGISTRATION_OTP_TTL_MINUTES = 10;

    /** حداقل فاصله بین دو درخواست ارسال کد برای یک شماره (ثانیه) */
    private const SHOP_REGISTRATION_OTP_RESEND_SECONDS = 90;

    /**
     * درخواست کد تأیید پیامکی قبل از ثبت‌نام نقش «فروشگاه».
     */
    public function sendRegistrationPhoneCode(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|numeric|digits:11',
        ]);
        $phone = $data['phone'];

        $cooldownKey = self::SHOP_REGISTRATION_OTP_COOLDOWN_PREFIX.$phone;
        $lastSent = Cache::get($cooldownKey);
        if ($lastSent !== null && (time() - (int) $lastSent) < self::SHOP_REGISTRATION_OTP_RESEND_SECONDS) {
            $wait = self::SHOP_REGISTRATION_OTP_RESEND_SECONDS - (time() - (int) $lastSent);

            return response([
                'message' => 'لطفاً قبل از درخواست مجدد چند لحظه صبر کنید.',
                'retry_after_seconds' => max(1, $wait),
            ], 429);
        }

        $code = (string) mt_rand(10000, 99999);
        Cache::put(
            self::SHOP_REGISTRATION_OTP_PREFIX.$phone,
            $code,
            now()->addMinutes(self::SHOP_REGISTRATION_OTP_TTL_MINUTES)
        );
        Cache::put($cooldownKey, time(), now()->addMinutes(self::SHOP_REGISTRATION_OTP_TTL_MINUTES + 1));

        $text = 'کد تأیید ثبت‌نام فروشگاه: '.$code;
        $smsResult = SmsTools::sendSms($phone, $text);

        return response([
            'message' => 'کد تأیید به شمارهٔ شما ارسال شد.',
            'smsResult' => $smsResult,
        ], 201);
    }

    public function register(Request $request)
    {
        $types = array_map('intval', (array) $request->input('type', []));
        $includesShop = in_array(User::USER_TYPE_KEY['فروشگاه'], $types, true);

        if ($includesShop) {
            $rules = [
                'name' => 'required|string',
                'last_name' => 'required|string',
                'type' => 'required|array|min:1',
                'type.*' => 'required|integer',
                'password' => 'required|string|max:255',
                'phone' => 'required|numeric|digits:11',
                'atelier_name' => 'required|string|max:255',
                'atelier_id' => 'nullable|numeric',
                'city_id' => 'nullable|numeric|exists:cities,id',
                'gender' => 'nullable|numeric|digits:1',
                'national_code' => 'nullable|string|digits:10|unique:users,national_code',
                'atelier_code' => 'nullable|string|max:50|unique:ateliers,code',
                'atelier_address' => 'nullable|string|max:255',
                'business_license' => 'nullable|string',
                'personality_image' => 'nullable|string',
                'birth_certificate' => 'nullable|string',
                'national_cart' => 'nullable|string',
                'tech_certificate' => 'nullable|string',
                'verification_code' => 'required|numeric|digits:5',
            ];
            if (in_array(User::USER_TYPE_KEY['فیلم بردار'], $types, true)) {
                $rules['tech_certificate'] = 'required|string';
            }
            $fields = $request->validate($rules);

            $expected = Cache::get(self::SHOP_REGISTRATION_OTP_PREFIX.$fields['phone']);
            if ($expected === null || (string) $expected !== (string) $fields['verification_code']) {
                return response()->json([
                    'message' => $expected === null
                        ? 'کد تأیید منقضی شده است. دوباره درخواست کد بدهید.'
                        : 'کد تأیید اشتباه است.',
                ], 422);
            }
            Cache::forget(self::SHOP_REGISTRATION_OTP_PREFIX.$fields['phone']);
            Cache::forget(self::SHOP_REGISTRATION_OTP_COOLDOWN_PREFIX.$fields['phone']);
        } else {
            $fields = $request->validate([
                'name' => 'required|string',
                'last_name' => 'required|string',
                'atelier_id' => 'nullable|numeric',
                'city_id' => 'required|numeric|exists:cities,id',
                'type' => 'required|array|min:1',
                'type.*' => 'required|numeric|digits:1',
                'gender' => 'required|numeric|digits:1',
                'password' => 'required|string|max:255',
                'phone' => 'required|numeric|digits:11',
                'national_code' => 'required|string|digits:10',
                'personality_image' => 'required|string',
                'birth_certificate' => 'required|string',
                'tech_certificate' => 'required_if:type,' . User::USER_TYPE_KEY['فیلم بردار'] . '|string',
                'national_cart' => 'required|string',
            ]);
        }

        $nationalCode = $fields['national_code']
            ?? $this->makeUniqueSyntheticNationalCode((string) $fields['phone']);

        $user = User::where('phone', $fields['phone'])->where('national_code', $nationalCode)->first();
        if (! $user) {
            $user = User::where('phone', $fields['phone'])->first();
            if ($user) {
                return response()->json([
                    'message' => 'این شمارهٔ موبایل قبلاً با حساب دیگری ثبت شده است.',
                ], 422);
            }
            $user = User::where('national_code', $nationalCode)->first();
            if ($user) {
                return response()->json([
                    'message' => 'این کد ملی قبلاً با حساب دیگری ثبت شده است.',
                ], 422);
            }

            $gender = $fields['gender'] ?? User::USER_GENDER_KEY['مرد'];

            $user = User::create([
                'name' => $fields['name'],
                'last_name' => $fields['last_name'],
                'atelier_id' => $fields['atelier_id'] ?? null,
                'city_id' => $fields['city_id'] ?? null,
                'gender' => $gender,
                'phone' => $fields['phone'],
                'national_code' => $nationalCode,
                'password' => bcrypt($fields['password']),
                'personality_image' => $this->saveImageField($request, 'personality_image', $nationalCode . '/personality_image.jpeg'),
                'birth_certificate' => $this->saveImageField($request, 'birth_certificate', $nationalCode . '/birth_certificate.jpeg'),
                'tech_certificate' => $this->saveImageField($request, 'tech_certificate', $nationalCode . '/tech_certificate.jpeg'),
                'national_cart' => $this->saveImageField($request, 'national_cart', $nationalCode . '/national_cart.jpeg'),
            ]);
        }

        $roles = array_map('intval', $user->roles()->select('id')->pluck('id')->toArray());
        $typeInts = array_map('intval', $fields['type']);
        $diffs = array_diff($typeInts, $roles);
        if (count($diffs)) {
            $user->roles()->attach($diffs);
            $user->save();
        }

        if (in_array(User::USER_TYPE_KEY['فروشگاه'], array_map('intval', (array) $request->input('type', [])), true)) {
            $atelierCode = $fields['atelier_code'] ?? $this->generateUniqueAtelierCode($fields['atelier_name'], (string) $fields['phone']);
            $address = $fields['atelier_address'] ?? '—';
            $businessLicense = $this->saveImageField(
                $request,
                'business_license',
                $nationalCode . '/business_license.jpeg'
            );

            $atelier = Atelier::create([
                'name' => $fields['atelier_name'],
                'code' => $atelierCode,
                'address' => $address,
                'business_license' => $businessLicense,
            ]);
            $user->update([
                'atelier_id' => $atelier->id,
                'shop_staff_role' => 'owner',
            ]);
        }

        $user->load('roles');
        $token = $user->createToken('myapptoken')->plainTextToken;

        return response([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    private function placeholderImageBinary(): string
    {
        return base64_decode(self::PLACEHOLDER_JPEG_BASE64, true) ?: '';
    }

    private function saveImageField(Request $request, string $key, string $relativePath): string
    {
        $raw = $request->input($key);
        if (is_string($raw) && $raw !== '') {
            $parts = explode(',', $raw, 2);

            return ImageTools::saveFile($relativePath, base64_decode($parts[1] ?? $parts[0], true) ?: $this->placeholderImageBinary());
        }

        return ImageTools::saveFile($relativePath, $this->placeholderImageBinary());
    }

    private function makeUniqueSyntheticNationalCode(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?: '0';
        $base = str_pad(substr($digits, -10), 10, '0', STR_PAD_LEFT);
        for ($i = 0; $i < 500; $i++) {
            $candidate = $base;
            if ($i > 0) {
                $suffix = str_pad((string) (($i % 99) + 1), 2, '0', STR_PAD_LEFT);
                $candidate = substr($base, 0, 8) . $suffix;
            }
            if (! User::where('national_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return str_pad((string) (time() % 10000000000), 10, '0', STR_PAD_LEFT);
    }

    private function generateUniqueAtelierCode(string $atelierName, string $phone): string
    {
        $slug = Str::slug($atelierName, '-');
        if ($slug === '') {
            $slug = 'shop';
        }
        $slug = substr($slug, 0, 40);
        $candidate = $slug;
        $n = 0;
        while (Atelier::where('code', $candidate)->exists()) {
            $n++;
            $candidate = substr($slug, 0, 32) . '-' . $n;
        }

        return $candidate;
    }

    public function login(Request $request)
    {
        $fields = $request->validate([
            'username' => 'required|string|digits:11',
            'password' => 'required|string',
        ]);

        // Check email
        $user = User::where('phone', $fields['username'])->first();

        // Check password
        if (! $user || ! Hash::check($fields['password'], $user->password)) {
            return response([
                'message' => 'اطلاعات وارد شده صحیح نیست',
            ], 401);
        }

        // پرسنل متصل به فروشگاه: فقط اگر دورهٔ دسترسی فروشگاه فعال باشد
        if ($user->atelier_id) {
            $atelier = Atelier::find($user->atelier_id);
            if (! $atelier || ! $atelier->isShopAccessActive()) {
                return response([
                    'message' => 'دسترسی فروشگاه شما غیرفعال است یا مدت استفاده به پایان رسیده است. با پشتیبانی تماس بگیرید.',
                ], 403);
            }
        }

        $token = $user->createToken('myapptoken')->plainTextToken;

        $user->load(['roles', 'atelier']);

        return response([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function logout(Request $request)
    {
        auth()->user()->tokens()->delete();

        return [
            'message' => 'Logged out',
        ];
    }

    public function resetPassword(Request $request)
    {
        $fields = $request->validate([
            'username' => 'required|string|digits:11',
            'nationalCode' => 'required|string|digits:10',
        ]);
        $user = User::where('phone', $fields['username'])->where('national_code', $fields['nationalCode'])->first();

        if (! $user) {
            return response([
                'message' => 'کاربر یافت نشد',
            ], 400);
        }
        $password = mt_rand(100000, 999999);

        $text = "رمز عبور شما : $password";
        $user->update([
            'password' => Hash::make($password),
        ]);
        $balance = SmsTools::sendSms($user->phone, $text);

        return response([
            'message' => 'پسوورد ارسال شد',
            'smsResult' => $balance,
        ], 201);
    }
}
