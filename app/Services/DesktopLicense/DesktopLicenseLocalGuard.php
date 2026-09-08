<?php

namespace App\Services\DesktopLicense;

use Carbon\Carbon;

/**
 * Verifies the Electron-written local license file (desktop mode API gate).
 */
class DesktopLicenseLocalGuard
{
    /** @var DesktopLicenseSigner */
    protected $signer;

    public function __construct(DesktopLicenseSigner $signer)
    {
        $this->signer = $signer;
    }

    /**
     * @return array{ok:bool,code?:string,message?:string,payload?:array}
     */
    public function check(): array
    {
        if (config('desktop_license.bypass') || env('WEBINOO_LICENSE_BYPASS') === '1') {
            return ['ok' => true, 'payload' => ['bypass' => true]];
        }

        if (!config('app.desktop_mode')) {
            return ['ok' => true];
        }

        if (!config('desktop_license.enabled')) {
            return ['ok' => true];
        }

        $path = config('desktop_license.license_file');
        if (!$path || !is_readable($path)) {
            return [
                'ok' => false,
                'code' => 'missing',
                'message' => 'فایل لایسنس دسکتاپ یافت نشد.',
            ];
        }

        $raw = json_decode(file_get_contents($path), true);
        $token = is_array($raw) ? ($raw['token'] ?? null) : null;
        if (!$token || !is_string($token)) {
            return [
                'ok' => false,
                'code' => 'invalid',
                'message' => 'فرمت لایسنس نامعتبر است.',
            ];
        }

        try {
            $payload = $this->signer->verify($token);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'code' => 'invalid',
                'message' => 'امضای لایسنس قابل بررسی نیست.',
            ];
        }

        if (!$payload) {
            return [
                'ok' => false,
                'code' => 'invalid',
                'message' => 'امضای لایسنس نامعتبر است.',
            ];
        }

        try {
            $expires = Carbon::parse($payload['expires_at']);
            $validated = Carbon::parse($payload['validated_at']);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'code' => 'invalid',
                'message' => 'تاریخ لایسنس نامعتبر است.',
            ];
        }

        if (now()->greaterThan($expires)) {
            return [
                'ok' => false,
                'code' => 'expired',
                'message' => 'اشتراک سالانه به پایان رسیده است.',
            ];
        }

        $graceDays = (int) config('desktop_license.offline_grace_days', 10);
        if (now()->greaterThan($validated->copy()->addDays($graceDays))) {
            return [
                'ok' => false,
                'code' => 'grace',
                'message' => "بیش از {$graceDays} روز بدون اعتبارسنجی آنلاین گذشته است.",
            ];
        }

        return ['ok' => true, 'payload' => $payload];
    }
}
