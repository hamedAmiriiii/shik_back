<?php

namespace App\Services\DesktopLicense;

use App\Models\DesktopLicense;
use App\Models\DesktopLicenseActivation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DesktopLicenseService
{
    /** @var DesktopLicenseSigner */
    protected $signer;

    public function __construct(DesktopLicenseSigner $signer)
    {
        $this->signer = $signer;
    }

    public function signer(): DesktopLicenseSigner
    {
        return $this->signer;
    }

    /**
     * @param  array<string,mixed>  $attrs
     */
    public function createLicense(array $attrs): DesktopLicense
    {
        $years = (int) ($attrs['years'] ?? config('desktop_license.subscription_years', 1));
        $starts = isset($attrs['starts_at'])
            ? Carbon::parse($attrs['starts_at'])
            : now();
        $expires = isset($attrs['expires_at'])
            ? Carbon::parse($attrs['expires_at'])
            : $starts->copy()->addYears(max(1, $years));

        return DesktopLicense::create([
            'license_key' => $attrs['license_key'] ?? $this->generateKey(),
            'customer_name' => $attrs['customer_name'] ?? null,
            'customer_phone' => $attrs['customer_phone'] ?? null,
            'customer_email' => $attrs['customer_email'] ?? null,
            'max_devices' => max(1, (int) ($attrs['max_devices'] ?? 1)),
            'starts_at' => $starts,
            'expires_at' => $expires,
            'status' => DesktopLicense::STATUS_ACTIVE,
            'features' => $attrs['features'] ?? ['admin', 'oil'],
            'notes' => $attrs['notes'] ?? null,
        ]);
    }

    public function generateKey(): string
    {
        $chunk = function () {
            return strtoupper(bin2hex(random_bytes(2)));
        };

        return 'WEBINOO-' . $chunk() . '-' . $chunk() . '-' . $chunk() . '-' . $chunk();
    }

    /**
     * @return array{token:string,license:DesktopLicense,activation:DesktopLicenseActivation}
     */
    public function activate(string $licenseKey, string $machineId, ?string $appVersion = null, ?string $platform = null): array
    {
        $key = strtoupper(trim($licenseKey));
        $machineId = trim($machineId);
        if ($key === '' || $machineId === '') {
            throw new RuntimeException('کلید لایسنس و شناسه دستگاه الزامی است.');
        }

        return DB::transaction(function () use ($key, $machineId, $appVersion, $platform) {
            /** @var DesktopLicense|null $license */
            $license = DesktopLicense::where('license_key', $key)->lockForUpdate()->first();
            if (!$license) {
                throw new RuntimeException('کلید لایسنس نامعتبر است.');
            }
            if (!$license->isUsable()) {
                throw new RuntimeException('این لایسنس منقضی یا باطل شده است.');
            }

            /** @var DesktopLicenseActivation|null $existing */
            $existing = $license->activations()
                ->where('machine_id', $machineId)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->revoked_at) {
                throw new RuntimeException('فعال‌سازی این دستگاه باطل شده است. با پشتیبانی تماس بگیرید.');
            }

            if (!$existing) {
                $activeCount = $license->activeActivations()->lockForUpdate()->count();
                if ($activeCount >= (int) $license->max_devices) {
                    throw new RuntimeException(
                        'حداکثر تعداد دستگاه‌های مجاز (' . $license->max_devices . ') پر شده است.'
                    );
                }
                $existing = DesktopLicenseActivation::create([
                    'desktop_license_id' => $license->id,
                    'machine_id' => $machineId,
                    'app_version' => $appVersion,
                    'platform' => $platform,
                    'activated_at' => now(),
                    'last_seen_at' => now(),
                ]);
            } else {
                $existing->last_seen_at = now();
                if ($appVersion) {
                    $existing->app_version = $appVersion;
                }
                if ($platform) {
                    $existing->platform = $platform;
                }
                $existing->save();
            }

            $token = $this->issueToken($license, $machineId);

            return [
                'token' => $token,
                'license' => $license,
                'activation' => $existing,
            ];
        });
    }

    /**
     * @return array{token:string,license:DesktopLicense}
     */
    public function validate(string $licenseKey, string $machineId): array
    {
        $key = strtoupper(trim($licenseKey));
        $machineId = trim($machineId);

        /** @var DesktopLicense|null $license */
        $license = DesktopLicense::where('license_key', $key)->first();
        if (!$license || !$license->isUsable()) {
            throw new RuntimeException('لایسنس نامعتبر یا منقضی است.');
        }

        $activation = $license->activeActivations()->where('machine_id', $machineId)->first();
        if (!$activation) {
            throw new RuntimeException('این دستگاه برای لایسنس ثبت نشده است.');
        }

        $activation->last_seen_at = now();
        $activation->save();

        return [
            'token' => $this->issueToken($license, $machineId),
            'license' => $license,
        ];
    }

    public function issueToken(DesktopLicense $license, string $machineId): string
    {
        $payload = [
            'v' => 1,
            'license_id' => (int) $license->id,
            'license_key' => $license->license_key,
            'machine_id' => $machineId,
            'customer_name' => (string) ($license->customer_name ?: ''),
            'max_devices' => (int) $license->max_devices,
            'starts_at' => optional($license->starts_at)->toIso8601String() ?: now()->toIso8601String(),
            'expires_at' => optional($license->expires_at)->toIso8601String() ?: now()->addYear()->toIso8601String(),
            'validated_at' => now()->toIso8601String(),
            'features' => $license->features ?: ['admin', 'oil'],
        ];

        return $this->signer->sign($payload);
    }
}
