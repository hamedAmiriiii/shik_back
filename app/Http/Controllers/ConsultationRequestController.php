<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\ConsultationRequest;
use App\Models\State;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ثبت عمومی درخواست مشاوره / خرید وبینو — بدون لاگین.
 */
class ConsultationRequestController extends Controller
{
    public const NOTIFY_PHONE = '09399166196';

    /**
     * GET /api/consultation-requests/form-options
     */
    public function formOptions()
    {
        return response([
            'states' => State::query()->orderBy('name')->get(['id', 'code', 'name']),
        ], 200);
    }

    /**
     * POST /api/consultation-requests
     */
    public function store(Request $request)
    {
        $fields = $request->validate([
            'name' => 'required|string|max:100',
            'phone' => 'required|string|regex:/^09[0-9]{9}$/',
            'state_id' => 'required|integer|exists:states,id',
            'city_id' => 'required|integer|exists:cities,id',
            'business_name' => 'required|string|max:255',
            'source' => 'nullable|string|max:64',
        ], [
            'name.required' => 'نام الزامی است.',
            'phone.required' => 'شماره موبایل الزامی است.',
            'phone.regex' => 'شماره موبایل باید ۱۱ رقم و با 09 شروع شود.',
            'state_id.required' => 'انتخاب استان الزامی است.',
            'city_id.required' => 'انتخاب شهر الزامی است.',
            'business_name.required' => 'نام مجموعه الزامی است.',
        ]);

        $city = City::find($fields['city_id']);
        $state = State::find($fields['state_id']);

        if (! $city || ! $state || ! $this->cityBelongsToState($city, $state)) {
            return response()->json([
                'message' => 'شهر انتخاب‌شده با استان همخوانی ندارد.',
            ], 422);
        }

        $duplicate = ConsultationRequest::query()
            ->where('phone', $fields['phone'])
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'با این شماره در ۲۴ ساعت گذشته درخواست ثبت شده است. همکاران ما به‌زودی تماس می‌گیرند.',
            ], 429);
        }

        $row = ConsultationRequest::create([
            'name' => trim($fields['name']),
            'phone' => $fields['phone'],
            'state_id' => (int) $fields['state_id'],
            'city_id' => (int) $fields['city_id'],
            'state_name' => $state->name ?? null,
            'city_name' => $city->name,
            'business_name' => trim($fields['business_name']),
            'source' => $fields['source'] ?? ConsultationRequest::SOURCE_DIGITAL_MENU,
            'status' => ConsultationRequest::STATUS_PENDING,
            'ip' => $request->ip(),
        ]);

        $this->notifyAdmin($row);
        $this->notifyApplicant($row);

        return response([
            'message' => 'درخواست شما ثبت شد. همکاران ما به‌زودی با شما تماس می‌گیرند.',
            'data' => $row,
        ], 201);
    }

    protected function cityBelongsToState(City $city, State $state): bool
    {
        $cityStateId = (string) $city->state_id;

        return $cityStateId === (string) $state->id
            || ($state->code !== null && $cityStateId === (string) $state->code);
    }

    protected function notifyAdmin(ConsultationRequest $row): void
    {
        $sourceLabel = ConsultationRequest::SOURCES[$row->source] ?? 'وبینو';

        $text = "درخواست مشاوره {$sourceLabel}\n"
            ."نام: {$row->name}\n"
            ."مجموعه: {$row->business_name}\n"
            ."استان/شهر: {$row->state_name} - {$row->city_name}\n"
            ."تلفن: {$row->phone}";

        try {
            SmsTools::sendSms(self::NOTIFY_PHONE, $text);
        } catch (\Throwable $e) {
            Log::warning('ارسال پیامک اعلان مشاوره ناموفق بود', [
                'consultation_request_id' => $row->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function notifyApplicant(ConsultationRequest $row): void
    {
        $name = trim((string) $row->name) ?: 'کاربر';
        $text = "{$name} عزیز از انتخاب شما متشکریم. همکاران ما به زودی با شما تماس خواهند گرفت\nوبینو";

        try {
            SmsTools::sendSms($row->phone, $text);
        } catch (\Throwable $e) {
            Log::warning('ارسال پیامک تأیید مشاوره به متقاضی ناموفق بود', [
                'consultation_request_id' => $row->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
