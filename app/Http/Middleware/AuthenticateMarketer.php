<?php

namespace App\Http\Middleware;

use App\Models\Marketer;
use App\Models\MarketerToken;
use Closure;
use Illuminate\Http\Request;

/**
 * احراز هویت پنل بازاریاب با هدر X-Marketer-Token (جدا از Sanctum تا به بقیهٔ API دسترسی نداشته باشد).
 */
class AuthenticateMarketer
{
    public const ATTRIBUTE = 'marketer';

    public function handle(Request $request, Closure $next)
    {
        $plain = (string) $request->header('X-Marketer-Token', '');
        $token = MarketerToken::findByPlain($plain);
        $marketer = $token?->marketer;

        if (! $marketer instanceof Marketer) {
            return response()->json(['message' => 'لطفاً دوباره وارد شوید.'], 401);
        }
        if (! $marketer->is_active) {
            return response()->json(['message' => 'حساب بازاریابی شما غیرفعال شده است.'], 403);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(10))) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        $request->attributes->set(self::ATTRIBUTE, $marketer);
        $request->attributes->set(self::ATTRIBUTE.'_token', $token);

        return $next($request);
    }
}
