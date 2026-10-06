<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketer;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Services\Marketing\MarketingService;
use Illuminate\Http\Request;

/**
 * ردیابی لینک بازاریاب: ثبت بازدید و انتساب فروشگاه تازه‌ثبت‌نام‌شده به بازاریاب.
 */
class MarketingTrackController extends Controller
{
    public function __construct(protected MarketingService $marketing)
    {
    }

    public function visit(Request $request)
    {
        $code = MarketingService::normalizeCode($request->input('code'));
        $visitorId = $this->normalizeVisitorId($request->input('visitor_id'));
        if ($code === null || $visitorId === null) {
            return response()->json(['message' => 'اطلاعات نامعتبر است.'], 422);
        }

        $marketer = Marketer::query()->where('code', $code)->where('is_active', true)->first();
        if (! $marketer) {
            return response()->json(['valid' => false], 404);
        }

        $this->marketing->recordVisit(
            $marketer,
            $visitorId,
            $request->ip(),
            $request->userAgent(),
            is_string($request->input('path')) ? $request->input('path') : null
        );

        return response()->json([
            'valid' => true,
            'attribution_days' => MarketingSetting::attributionDays(),
        ]);
    }

    public function claim(Request $request)
    {
        $actor = $this->shopRequestActor($request);
        if ($actor === null) {
            return response()->json(['ok' => false, 'final' => false, 'message' => 'لطفاً وارد شوید.'], 401);
        }
        if (! $actor instanceof User) {
            return response()->json(['ok' => false, 'final' => true, 'message' => 'فقط حساب فروشگاه قابل ثبت است.']);
        }

        $code = MarketingService::normalizeCode($request->input('code') ?? $request->input('marketer_code'));
        if ($code === null) {
            return response()->json(['ok' => false, 'final' => true, 'message' => 'کد بازاریاب معتبر نیست.'], 422);
        }

        $result = $this->marketing->claim($actor, $code, $this->normalizeVisitorId($request->input('visitor_id')));

        return response()->json($result);
    }

    protected function normalizeVisitorId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return preg_match('/^[A-Za-z0-9\-]{8,64}$/', $value) ? $value : null;
    }
}
