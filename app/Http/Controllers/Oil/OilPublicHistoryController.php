<?php

namespace App\Http\Controllers\Oil;

use App\Http\Controllers\Controller;
use App\Services\OilPublicHistoryService;
use Illuminate\Http\Request;

class OilPublicHistoryController extends Controller
{
    public function show(Request $request, string $phone)
    {
        $data = OilPublicHistoryService::payload($phone);
        if (! $data) {
            return response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422);
        }

        return response()->json($data);
    }
}
