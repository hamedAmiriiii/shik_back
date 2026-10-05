<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Marketer;
use App\Models\MarketerPayout;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Services\Marketing\MarketingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * مدیریت بازاریاب‌ها برای ادمین سامانه: گرید عملکرد، درصد پورسانت و تسویه.
 */
class MarketerAdminController extends Controller
{
    public function __construct(protected MarketingService $marketing)
    {
    }

    public function index(Request $request)
    {
        $this->requirePlatformAdmin($request);

        try {
            $this->marketing->syncCommissions();
        } catch (\Throwable $e) {
            report($e);
        }

        $marketers = Marketer::query()->orderByDesc('id')->get();
        $summaries = $this->marketing->summaries($marketers->pluck('id')->all());

        $rows = $marketers->map(fn (Marketer $m) => array_merge(
            $this->marketing->formatMarketer($m, true),
            $summaries->get($m->id, [])
        ))->values();

        $totals = [
            'marketers_count' => $marketers->count(),
            'registered_count' => (int) $rows->sum('registered_count'),
            'paid_shops_count' => (int) $rows->sum('paid_shops_count'),
            'total_sales_toman' => (int) $rows->sum('total_sales_toman'),
            'total_commission_toman' => (int) $rows->sum('total_commission_toman'),
            'total_paid_toman' => (int) $rows->sum('total_paid_toman'),
            'balance_toman' => (int) $rows->sum('balance_toman'),
        ];

        return response()->json([
            'data' => $rows,
            'totals' => $totals,
            'settings' => MarketingSetting::allForApi(),
        ]);
    }

    public function show(Request $request, Marketer $marketer)
    {
        $this->requirePlatformAdmin($request);

        try {
            $this->marketing->syncCommissions($marketer);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'marketer' => $this->marketing->formatMarketer($marketer, true),
            'summary' => $this->marketing->summaryFor($marketer),
            'referrals' => $this->marketing->referralsFor($marketer, false),
            'payouts' => $this->marketing->payoutsFor($marketer),
        ]);
    }

    public function store(Request $request)
    {
        $this->requirePlatformAdmin($request);

        $phone = MarketingService::normalizePhone($request->input('phone'));
        if ($phone === null) {
            return response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422);
        }
        if (Marketer::query()->where('phone', $phone)->exists()) {
            return response()->json(['message' => 'این شماره قبلاً به‌عنوان بازاریاب ثبت شده است.'], 422);
        }

        $data = $this->validateAdminFields($request);
        $marketer = $this->marketing->findOrCreateByPhone($phone);
        $this->applyAdminFields($data, $marketer);

        return response()->json(['data' => $this->marketing->formatMarketer($marketer->fresh(), true)], 201);
    }

    public function update(Request $request, Marketer $marketer)
    {
        $this->requirePlatformAdmin($request);
        $this->applyAdminFields($this->validateAdminFields($request), $marketer);

        return response()->json(['data' => $this->marketing->formatMarketer($marketer->fresh(), true)]);
    }

    public function settings(Request $request)
    {
        $this->requirePlatformAdmin($request);

        return response()->json(['data' => MarketingSetting::allForApi()]);
    }

    public function updateSettings(Request $request)
    {
        $this->requirePlatformAdmin($request);

        $data = $request->validate([
            'default_commission_percent' => 'required|numeric|min:0|max:100',
            'attribution_days' => 'required|integer|min:1|max:3650',
        ]);

        MarketingSetting::put(MarketingSetting::DEFAULT_COMMISSION_PERCENT, (string) round((float) $data['default_commission_percent'], 2));
        MarketingSetting::put(MarketingSetting::ATTRIBUTION_DAYS, (string) (int) $data['attribution_days']);

        return response()->json(['data' => MarketingSetting::allForApi()]);
    }

    public function storePayout(Request $request, Marketer $marketer)
    {
        $admin = $this->requirePlatformAdmin($request);

        $data = $request->validate([
            'amount_toman' => 'required|integer|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        $payout = DB::transaction(function () use ($marketer, $data, $admin) {
            Marketer::query()->whereKey($marketer->id)->lockForUpdate()->first();
            $balance = $this->marketing->summaryFor($marketer)['balance_toman'];
            if ((int) $data['amount_toman'] > $balance) {
                abort(response()->json([
                    'message' => 'مبلغ تسویه از مانده حساب بازاریاب بیشتر است.',
                    'balance_toman' => $balance,
                ], 422));
            }

            return MarketerPayout::create([
                'marketer_id' => $marketer->id,
                'amount_toman' => (int) $data['amount_toman'],
                'note' => $data['note'] ?? null,
                'paid_at' => now(),
                'created_by_user_id' => $admin->id,
            ]);
        });

        return response()->json([
            'data' => ['id' => $payout->id],
            'summary' => $this->marketing->summaryFor($marketer),
        ], 201);
    }

    public function destroyPayout(Request $request, Marketer $marketer, MarketerPayout $payout)
    {
        $this->requirePlatformAdmin($request);
        if ((int) $payout->marketer_id !== (int) $marketer->id) {
            return response()->json(['message' => 'تسویه یافت نشد.'], 404);
        }

        $payout->delete();

        return response()->json(['summary' => $this->marketing->summaryFor($marketer)]);
    }

    /** @return array<string, mixed> */
    protected function validateAdminFields(Request $request): array
    {
        return $request->validate([
            'name' => 'sometimes|nullable|string|max:120',
            'custom_commission_percent' => 'sometimes|nullable|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
            'admin_note' => 'sometimes|nullable|string|max:2000',
            'card_number' => 'sometimes|nullable|string|max:32',
            'sheba' => 'sometimes|nullable|string|max:32',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function applyAdminFields(array $data, Marketer $marketer): void
    {
        $updates = [];
        foreach (['name', 'admin_note', 'card_number', 'sheba'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = trim((string) $data[$key]);
                $updates[$key] = $value !== '' ? $value : null;
            }
        }
        if (array_key_exists('custom_commission_percent', $data)) {
            $updates['commission_percent'] = $data['custom_commission_percent'] !== null
                ? round((float) $data['custom_commission_percent'], 2)
                : null;
        }
        if (array_key_exists('is_active', $data)) {
            $updates['is_active'] = (bool) $data['is_active'];
            if (! $updates['is_active']) {
                $marketer->tokens()->delete();
            }
        }

        if ($updates !== []) {
            $marketer->update($updates);
        }
    }

    protected function requirePlatformAdmin(Request $request): User
    {
        $actor = $this->shopRequestActor($request);
        if ($actor instanceof Customer) {
            abort(response()->json(['message' => 'این عملیات فقط برای ادمین است.'], 403));
        }
        if (! $actor instanceof User) {
            abort(response()->json(['message' => 'لطفاً وارد شوید.'], 401));
        }
        if (! $actor->roles()->where('id', User::USER_TYPE_KEY['ادمین'])->exists()) {
            abort(response()->json(['message' => 'فقط ادمین می‌تواند بازاریاب‌ها را مدیریت کند.'], 403));
        }

        return $actor;
    }
}
