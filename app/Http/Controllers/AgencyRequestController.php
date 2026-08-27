<?php

namespace App\Http\Controllers;

use App\Models\AgencyRequest;
use App\Models\City;
use App\Models\State;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * ثبت عمومی درخواست اعطای نمایندگی وبینو — بدون نیاز به لاگین.
 */
class AgencyRequestController extends Controller
{
    /** شماره‌ای که اعلان درخواست جدید برایش ارسال می‌شود */
    public const NOTIFY_PHONE = '09399166196';

    /**
     * فرم‌های موردنیاز فرانت (استان‌ها و مدارک تحصیلی).
     * GET /api/agency-requests/form-options
     */
    public function formOptions()
    {
        return response([
            'educations' => AgencyRequest::EDUCATIONS,
            'states' => State::query()->orderBy('name')->get(['id', 'code', 'name']),
        ], 200);
    }

    /**
     * ثبت درخواست نمایندگی.
     * POST /api/agency-requests
     */
    public function store(Request $request)
    {
        $fields = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'state_id' => 'required|integer|exists:states,id',
            'city_id' => 'required|integer|exists:cities,id',
            'phone' => 'required|string|regex:/^09[0-9]{9}$/',
            'education' => ['required', 'string', Rule::in(AgencyRequest::EDUCATIONS)],
        ], [
            'first_name.required' => 'نام الزامی است.',
            'last_name.required' => 'نام خانوادگی الزامی است.',
            'state_id.required' => 'انتخاب استان الزامی است.',
            'state_id.exists' => 'استان انتخاب‌شده معتبر نیست.',
            'city_id.required' => 'انتخاب شهر الزامی است.',
            'city_id.exists' => 'شهر انتخاب‌شده معتبر نیست.',
            'phone.required' => 'شماره تلفن الزامی است.',
            'phone.regex' => 'شماره تلفن باید ۱۱ رقم و با 09 شروع شود.',
            'education.required' => 'مدرک تحصیلی الزامی است.',
            'education.in' => 'مدرک تحصیلی انتخاب‌شده معتبر نیست.',
        ]);

        $city = City::find($fields['city_id']);
        $state = State::find($fields['state_id']);

        if (! $city || ! $state || ! $this->cityBelongsToState($city, $state)) {
            return response()->json([
                'message' => 'شهر انتخاب‌شده با استان همخوانی ندارد.',
            ], 422);
        }

        // جلوگیری از ثبت تکراری با همان شماره در ۲۴ ساعت اخیر
        $duplicate = AgencyRequest::query()
            ->where('phone', $fields['phone'])
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'با این شماره در ۲۴ ساعت گذشته درخواست ثبت شده است. کارشناسان ما با شما تماس می‌گیرند.',
            ], 429);
        }

        $agencyRequest = AgencyRequest::create([
            'first_name' => trim($fields['first_name']),
            'last_name' => trim($fields['last_name']),
            'state_id' => (int) $fields['state_id'],
            'city_id' => (int) $fields['city_id'],
            'state_name' => $state->name ?? null,
            'city_name' => $city->name,
            'phone' => $fields['phone'],
            'education' => $fields['education'],
            'status' => AgencyRequest::STATUS_PENDING,
            'ip' => $request->ip(),
        ]);

        $this->notifyAdmin($agencyRequest);

        return response([
            'message' => 'درخواست نمایندگی شما ثبت شد. کارشناسان ما به‌زودی با شما تماس می‌گیرند.',
            'data' => $agencyRequest,
        ], 201);
    }

    /**
     * در بخشی از داده‌ها cities.state_id به states.id اشاره می‌کند و در بخشی به states.code،
     * بنابراین هر دو حالت را معتبر می‌شماریم.
     */
    protected function cityBelongsToState(City $city, State $state): bool
    {
        $cityStateId = (string) $city->state_id;

        return $cityStateId === (string) $state->id
            || ($state->code !== null && $cityStateId === (string) $state->code);
    }

    /**
     * اعلام درخواست جدید به شماره پشتیبانی. خطای پیامک نباید ثبت درخواست را خراب کند.
     */
    protected function notifyAdmin(AgencyRequest $agencyRequest): void
    {
        $text = "درخواست نمایندگی وبینو\n"
            ."نام: {$agencyRequest->full_name}\n"
            ."استان: {$agencyRequest->state_name} - شهر: {$agencyRequest->city_name}\n"
            ."تلفن: {$agencyRequest->phone}\n"
            ."مدرک: {$agencyRequest->education}";

        try {
            SmsTools::sendSms(self::NOTIFY_PHONE, $text);
        } catch (\Throwable $e) {
            Log::warning('ارسال پیامک درخواست نمایندگی ناموفق بود', [
                'agency_request_id' => $agencyRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
