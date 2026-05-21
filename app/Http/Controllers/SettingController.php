<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

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
        $this->bindShopSettingAtelierFromRequest($request);
        $value = Setting::get($key);
        return response(['key' => $key, 'value' => $value], 200);
    }

    /**
     * به‌روزرسانی یک setting
     */
    public function update(Request $request, $key)
    {
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
}

