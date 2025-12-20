<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * دریافت همه تنظیمات
     */
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key');
        return response($settings, 200);
    }

    /**
     * دریافت یک setting خاص
     */
    public function show($key)
    {
        $value = Setting::get($key);
        return response(['key' => $key, 'value' => $value], 200);
    }

    /**
     * به‌روزرسانی یک setting
     */
    public function update(Request $request, $key)
    {
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
    public function getLoyaltyCreditStatus()
    {
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
}

