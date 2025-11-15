<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckCityAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        // If user is not authenticated or is an admin, allow access
        if (!$user || $user->hasRole('ادمین')) {
            return $next($request);
        }

        // Get the resource from the route parameters
        $route = $request->route();
        $parameters = $route->parameters();
        
        // Check if the resource has city_id and if it matches the admin's city_id
        foreach (['talar', 'garden', 'ceremony'] as $resource) {
            if (isset($parameters[$resource]) && $parameters[$resource]) {
                $model = $parameters[$resource];
                
                // If model has city_id and it doesn't match the admin's city_id, deny access
                if (isset($model->city_id) && $model->city_id !== $user->city_id) {
                    return response()->json([
                        'message' => 'شما مجوز دسترسی به این منبع را ندارید.'
                    ], 403);
                }
                
                // For ceremony, check related models (talar and garden)
                if ($resource === 'ceremony') {
                    if (($model->talar && $model->talar->city_id !== $user->city_id) || 
                        ($model->garden && $model->garden->city_id !== $user->city_id)) {
                        return response()->json([
                            'message' => 'شما مجوز دسترسی به این مراسم را ندارید.'
                        ], 403);
                    }
                }
            }
        }

        return $next($request);
    }
}
