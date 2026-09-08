<?php

namespace App\Http\Middleware;

use App\Services\DesktopLicense\DesktopLicenseLocalGuard;
use Closure;
use Illuminate\Http\Request;

class EnsureDesktopLicense
{
    /** @var DesktopLicenseLocalGuard */
    protected $guard;

    public function __construct(DesktopLicenseLocalGuard $guard)
    {
        $this->guard = $guard;
    }

    public function handle(Request $request, Closure $next)
    {
        if (!config('app.desktop_mode')) {
            return $next($request);
        }

        // License server endpoints are for cloud; skip if somehow hit locally
        if ($request->is('api/desktop-license/*')) {
            return $next($request);
        }

        $result = $this->guard->check();
        if (!empty($result['ok'])) {
            return $next($request);
        }

        return response()->json([
            'hasError' => true,
            'code' => $result['code'] ?? 'LICENSE_INVALID',
            'message' => $result['message'] ?? 'لایسنس دسکتاپ معتبر نیست.',
        ], 403);
    }
}
