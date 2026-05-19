<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * اگر توکن در Authorization نیست ولی در body/هدر جایگزین است، کاربر را برای Sanctum ست می‌کند.
 * مسیرهای auth:sanctum بدون هدر Bearer استاندارد هم کار می‌کنند.
 */
class ResolveSanctumUserFromRequest
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user('sanctum')) {
            return $next($request);
        }

        $plainToken = $this->extractToken($request);
        if ($plainToken === null) {
            return $next($request);
        }

        $accessToken = PersonalAccessToken::findToken($plainToken);
        if (! $accessToken || ! $accessToken->tokenable) {
            return $next($request);
        }

        $user = $accessToken->tokenable;
        Auth::guard('sanctum')->setUser($user);
        $request->setUserResolver(static function () use ($user) {
            return $user;
        });

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return trim($bearer);
        }

        $authorization = $request->header('Authorization');
        if (is_string($authorization) && $authorization !== '') {
            if (stripos($authorization, 'Bearer ') === 0) {
                return trim(substr($authorization, 7));
            }

            return trim($authorization);
        }

        foreach (['X-Auth-Token', 'X-Api-Token'] as $header) {
            $value = $request->header($header);
            if (is_string($value) && $value !== '') {
                return trim($value);
            }
        }

        foreach (['token', 'access_token', 'auth_token', 'bearer_token'] as $key) {
            $value = $request->input($key);
            if (is_string($value) && $value !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
