<?php

namespace App\Http\Controllers;

use App\Services\DesktopLicense\DesktopLicenseService;
use Illuminate\Http\Request;
use RuntimeException;

class DesktopLicenseController extends Controller
{
    /** @var DesktopLicenseService */
    protected $licenses;

    public function __construct(DesktopLicenseService $licenses)
    {
        $this->licenses = $licenses;
    }

    public function activate(Request $request)
    {
        $data = $request->validate([
            'license_key' => 'required|string|max:64',
            'machine_id' => 'required|string|max:128',
            'app_version' => 'nullable|string|max:32',
            'platform' => 'nullable|string|max:32',
        ]);

        try {
            $result = $this->licenses->activate(
                $data['license_key'],
                $data['machine_id'],
                $data['app_version'] ?? null,
                $data['platform'] ?? null
            );
            $license = $result['license'];

            return response()->json([
                'token' => $result['token'],
                'message' => 'لایسنس با موفقیت فعال شد.',
                'license' => [
                    'license_key' => $license->license_key,
                    'customer_name' => $license->customer_name,
                    'max_devices' => $license->max_devices,
                    'expires_at' => optional($license->expires_at)->toIso8601String(),
                    'features' => $license->features,
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function validateLicense(Request $request)
    {
        $data = $request->validate([
            'license_key' => 'required|string|max:64',
            'machine_id' => 'required|string|max:128',
            'token' => 'nullable|string',
        ]);

        try {
            $result = $this->licenses->validate($data['license_key'], $data['machine_id']);
            $license = $result['license'];

            return response()->json([
                'token' => $result['token'],
                'message' => 'اعتبار لایسنس تمدید شد.',
                'license' => [
                    'license_key' => $license->license_key,
                    'expires_at' => optional($license->expires_at)->toIso8601String(),
                    'max_devices' => $license->max_devices,
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
