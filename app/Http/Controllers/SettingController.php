<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\ShopLoyaltyCreditTierService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SettingController extends Controller
{
    /** فقط ادمین از API جداگانه می‌تواند شارژ کند */
    private const ADMIN_ONLY_KEYS = ['shop_sms_quota'];

    /**
     * دریافت همه تنظیمات
     */
    public function index(Request $request)
    {
        $this->bindShopSettingAtelierFromRequest($request);
        $q = Setting::query();
        $aid = $this->staffShopAtelierId($request);
        if ($aid !== null) {
            $q->where('atelier_id', $aid);
        } else {
            $q->whereNull('atelier_id');
        }
        $settings = $q->get()->pluck('value', 'key');

        return response($settings, 200);
    }

    /**
     * دریافت یک setting خاص
     */
    public function show(Request $request, $key)
    {
        if ($key === 'loyalty-credit-tiers') {
            return $this->getLoyaltyCreditTiers($request);
        }

        $this->bindShopSettingAtelierFromRequest($request);
        $value = Setting::get($key);
        return response(['key' => $key, 'value' => $value], 200);
    }

    /**
     * به‌روزرسانی یک setting
     */
    public function update(Request $request, $key)
    {
        if ($key === 'loyalty-credit-tiers') {
            return $this->setLoyaltyCreditTiers($request);
        }

        if (in_array($key, self::ADMIN_ONLY_KEYS, true)) {
            return response()->json([
                'message' => 'اعتبار پیامک فقط توسط ادمین قابل شارژ است.',
            ], 403);
        }

        $this->bindShopSettingAtelierFromRequest($request);
        $request->validate([
            'value' => 'required',
        ]);

        Setting::set($key, $request->input('value'));
        
        return response([
            'message' => 'تنظیمات با موفقیت به‌روزرسانی شد',
            'key' => $key,
            'value' => $request->input('value')
        ], 200);
    }

    /**
     * دریافت وضعیت فعال بودن اعتبار
     */
    public function getLoyaltyCreditStatus(Request $request)
    {
        $this->bindShopSettingAtelierFromRequest($request);
        $isEnabled = Setting::isEnabled('enable_loyalty_credit', true);
        return response([
            'enable_loyalty_credit' => $isEnabled,
            'value' => $isEnabled ? '1' : '0'
        ], 200);
    }

    /**
     * تغییر وضعیت فعال بودن اعتبار
     */
    public function toggleLoyaltyCredit(Request $request)
    {
        $this->bindShopSettingAtelierFromRequest($request);
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $value = $request->input('enabled') ? '1' : '0';
        Setting::set('enable_loyalty_credit', $value);
        
        return response([
            'message' => 'وضعیت اعتبار با موفقیت تغییر کرد',
            'enable_loyalty_credit' => $request->input('enabled'),
            'value' => $value
        ], 200);
    }

    /**
     * دریافت تعداد روز انقضای اعتبار
     */
    public function getCreditExpiryDays(Request $request)
    {
        $this->bindShopSettingAtelierFromRequest($request);
        $days = (int) Setting::get('credit_expiry_days', 60);
        return response([
            'key' => 'credit_expiry_days',
            'value' => (string) $days,
            'days' => $days
        ], 200);
    }

    /**
     * تنظیم تعداد روز انقضای اعتبار
     */
    public function setCreditExpiryDays(Request $request)
    {
        $this->bindShopSettingAtelierFromRequest($request);
        $request->validate([
            'days' => 'required|integer|min:1|max:365',
        ]);

        $days = $request->input('days');
        Setting::set('credit_expiry_days', (string) $days);
        
        return response([
            'message' => 'تعداد روز انقضای اعتبار با موفقیت تنظیم شد',
            'key' => 'credit_expiry_days',
            'value' => (string) $days,
            'days' => $days
        ], 200);
    }

    /**
     * ایجاد یا به‌روزرسانی یک setting (POST)
     */
    public function store(Request $request)
    {
        $this->bindShopSettingAtelierFromRequest($request);
        $request->validate([
            'key' => 'required|string|max:255',
            'value' => 'required',
        ]);

        if (in_array($request->input('key'), self::ADMIN_ONLY_KEYS, true)) {
            return response()->json([
                'message' => 'اعتبار پیامک فقط توسط ادمین قابل شارژ است.',
            ], 403);
        }

        Setting::set($request->input('key'), $request->input('value'));
        
        return response([
            'message' => 'تنظیمات با موفقیت ایجاد/به‌روزرسانی شد',
            'key' => $request->input('key'),
            'value' => $request->input('value')
        ], 201);
    }

    /**
     * بازه‌های درصد اعتبار وفاداری فروشگاه (حداکثر ۵ بازه).
     */
    public function getLoyaltyCreditTiers(Request $request)
    {
        $this->bindShopSettingAtelierFromRequest($request);
        $atelierId = $this->staffShopAtelierId($request);

        return response([
            'max_tiers' => \App\Models\ShopLoyaltyCreditTier::MAX_TIERS_PER_SHOP,
            'tiers' => ShopLoyaltyCreditTierService::tiersForApi($atelierId),
        ], 200);
    }

    /**
     * ثبت بازه‌های درصد اعتبار وفاداری فروشگاه.
     *
     * tiers: [{ max_amount: 1000000, percent: 3 }, { max_amount: 2000000, percent: 4 }, { max_amount: null, percent: 5 }]
     */
    public function setLoyaltyCreditTiers(Request $request)
    {
        $this->bindShopSettingAtelierFromRequest($request);
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'تنظیم بازه‌های اعتبار فقط برای حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $request->validate([
            'tiers' => 'required|array|min:1|max:'.\App\Models\ShopLoyaltyCreditTier::MAX_TIERS_PER_SHOP,
            'tiers.*.max_amount' => 'nullable|numeric|min:1',
            'tiers.*.percent' => 'nullable|numeric|min:0|max:100',
            'tiers.*.value' => 'nullable|numeric|min:0|max:100',
        ]);

        $tiers = $this->normalizeLoyaltyCreditTiersInput($request->input('tiers', []));

        try {
            ShopLoyaltyCreditTierService::syncTiers($atelierId, $tiers);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'بازه‌های اعتبار با موفقیت ثبت شد.',
            'max_tiers' => \App\Models\ShopLoyaltyCreditTier::MAX_TIERS_PER_SHOP,
            'tiers' => ShopLoyaltyCreditTierService::tiersForApi($atelierId),
        ], 200);
    }

    /**
     * دریافت نرخ سود ماهانه اقساط
     */
    public function getInstallmentInterestRate(Request $request)
    {
        $this->bindShopSettingAtelierFromRequest($request);
        $rate = (float) Setting::get('installment_monthly_interest_rate', 0);
        return response([
            'key' => 'installment_monthly_interest_rate',
            'value' => (string) $rate,
            'rate' => $rate,
            'rate_percent' => $rate . '%'
        ], 200);
    }

    /**
     * تنظیم نرخ سود ماهانه اقساط
     */
    public function setInstallmentInterestRate(Request $request)
    {
        $this->bindShopSettingAtelierFromRequest($request);
        $request->validate([
            'rate' => 'required|numeric|min:0|max:100',
        ]);

        $rate = $request->input('rate');
        Setting::set('installment_monthly_interest_rate', (string) $rate);
        
        return response([
            'message' => 'نرخ سود ماهانه با موفقیت تنظیم شد',
            'key' => 'installment_monthly_interest_rate',
            'value' => (string) $rate,
            'rate' => $rate,
            'rate_percent' => $rate . '%'
        ], 200);
    }

    /**
     * دریافت تنظیمات حقوق کارمندان فروشگاه.
     */
    public function getPayrollSettings(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        Setting::setContextAtelierId($atelierId);

        $hourlyWage = (float) Setting::get('salary_hourly_wage', '0');
        $monthlyWorkHours = (float) Setting::get('salary_monthly_work_hours', '220');

        return response([
            'salary_hourly_wage' => $hourlyWage,
            'salary_monthly_work_hours' => $monthlyWorkHours,
        ], 200);
    }

    /**
     * تنظیم حقوق پایه ساعتی و ساعات کار ماهانه.
     */
    public function setPayrollSettings(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        Setting::setContextAtelierId($atelierId);

        $this->mergeRequestPayload($request, [
            'salary_hourly_wage',
            'salary_monthly_work_hours',
            'hourly_wage',
            'monthly_work_hours',
        ]);

        if (! $request->has('salary_hourly_wage') && $request->has('hourly_wage')) {
            $request->merge(['salary_hourly_wage' => $request->input('hourly_wage')]);
        }
        if (! $request->has('salary_monthly_work_hours') && $request->has('monthly_work_hours')) {
            $request->merge(['salary_monthly_work_hours' => $request->input('monthly_work_hours')]);
        }

        $request->validate([
            'salary_hourly_wage' => 'required|numeric|min:0',
            'salary_monthly_work_hours' => 'required|numeric|min:1|max:744',
        ]);

        $hourlyWage = (float) $request->input('salary_hourly_wage');
        $monthlyWorkHours = (float) $request->input('salary_monthly_work_hours');

        Setting::set('salary_hourly_wage', (string) $hourlyWage);
        Setting::set('salary_monthly_work_hours', (string) $monthlyWorkHours);

        return response([
            'message' => 'تنظیمات حقوق با موفقیت ذخیره شد.',
            'salary_hourly_wage' => $hourlyWage,
            'salary_monthly_work_hours' => $monthlyWorkHours,
        ], 200);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tiers
     * @return array<int, array{max_amount: mixed, percent: float|int|string}>
     */
    protected function normalizeLoyaltyCreditTiersInput(array $tiers): array
    {
        $normalized = [];

        foreach ($tiers as $index => $tier) {
            $percent = $tier['percent'] ?? $tier['value'] ?? null;
            if ($percent === null || $percent === '') {
                throw new InvalidArgumentException('درصد بازه '.($index + 1).' الزامی است.');
            }

            $normalized[] = [
                'max_amount' => $tier['max_amount'] ?? null,
                'percent' => $percent,
            ];
        }

        return $normalized;
    }
}

