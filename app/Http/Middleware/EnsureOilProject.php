<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ProjectType;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class EnsureOilProject
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('sanctum');
        if (! $user instanceof User) {
            return response()->json(['message' => 'لطفاً وارد شوید.'], 401);
        }

        if (! Schema::hasColumn('users', 'project_type')) {
            return response()->json(['message' => 'این بخش هنوز راه‌اندازی نشده است.'], 503);
        }

        if (ProjectType::normalize($user->project_type) !== ProjectType::OIL) {
            return response()->json([
                'message' => 'این حساب مربوط به فروشگاه است. اپ تعویض روغن فقط با حساب تعویض روغن باز می‌شود.',
            ], 403);
        }

        return $next($request);
    }
}
