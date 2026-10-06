<?php

namespace App\Services\Marketing;

use App\Models\Atelier;
use App\Models\GatewayPayment;
use App\Models\Marketer;
use App\Models\MarketerCommission;
use App\Models\MarketerPayout;
use App\Models\MarketerReferral;
use App\Models\MarketerVisit;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Services\ShopStaffAccess;
use App\Tools\SmsTools;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketingService
{
    /** کلیک‌های تکراری یک بازدیدکننده در این بازه یک بازدید حساب می‌شوند */
    private const VISIT_DEDUPE_MINUTES = 30;

    /** اگر ثبت بازدید از دست رفته باشد، فروشگاه تا این مدت بعد از ثبت‌نام هنوز قابل انتساب است */
    private const CLAIM_WITHOUT_VISIT_HOURS = 48;

    public static function toLatinDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', self::toLatinDigits((string) $phone));
        if (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^09\d{9}$/', $digits) ? $digits : null;
    }

    public static function maskPhone(?string $phone): ?string
    {
        if (! is_string($phone) || strlen($phone) < 7) {
            return $phone;
        }

        return substr($phone, 0, 4).'***'.substr($phone, -4);
    }

    public static function normalizeCode(?string $code): ?string
    {
        $raw = strtoupper(trim(self::toLatinDigits((string) $code)));
        // کد قدیمی منوی آنلاین → ۴ رقمی
        if ($raw === '4Z3T6F') {
            return '4366';
        }
        $digits = preg_replace('/\D/', '', $raw);

        return preg_match('/^\d{4}$/', $digits) ? $digits : null;
    }

    public function referralLink(Marketer $marketer): string
    {
        return rtrim((string) config('marketing.site_url'), '/').'/?mref='.$marketer->code;
    }

    public function findOrCreateByPhone(string $phone): Marketer
    {
        $marketer = Marketer::query()->where('phone', $phone)->first();
        if ($marketer) {
            return $marketer;
        }

        try {
            return Marketer::create([
                'phone' => $phone,
                'code' => Marketer::generateUniqueCode(),
                'is_active' => true,
            ]);
        } catch (QueryException $e) {
            return Marketer::query()->where('phone', $phone)->firstOrFail();
        }
    }

    public function recordVisit(Marketer $marketer, string $visitorId, ?string $ip, ?string $userAgent, ?string $path): void
    {
        $recent = MarketerVisit::query()
            ->where('marketer_id', $marketer->id)
            ->where('visitor_id', $visitorId)
            ->where('created_at', '>=', now()->subMinutes(self::VISIT_DEDUPE_MINUTES))
            ->exists();
        if ($recent) {
            return;
        }

        MarketerVisit::create([
            'marketer_id' => $marketer->id,
            'visitor_id' => $visitorId,
            'ip' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'landing_path' => $path !== null ? mb_substr($path, 0, 255) : null,
        ]);
    }

    /**
     * فروشگاه کاربر لاگین‌شده را به بازاریابی که با لینکش آمده نسبت می‌دهد.
     *
     * final=true یعنی فرانت دیگر نباید دوباره تلاش کند.
     *
     * @return array{ok: bool, final: bool, message: string}
     */
    public function claim(User $user, string $code, ?string $visitorId): array
    {
        if (! Schema::hasTable('marketers') || ! Schema::hasTable('marketer_referrals')) {
            return [
                'ok' => false,
                'final' => true,
                'message' => 'جداول بازاریاب روی سرور موجود نیست.',
            ];
        }

        $code = self::normalizeCode($code) ?? trim($code);
        $marketer = Marketer::query()->where('code', $code)->where('is_active', true)->first();
        if (! $marketer) {
            // اگر کد فقط رقم است ولی is_active=0 باشد هم گزارش بده
            $any = Marketer::query()->where('code', $code)->first();
            return [
                'ok' => false,
                'final' => true,
                'message' => $any
                    ? 'این کد بازاریاب غیرفعال است.'
                    : 'کد بازاریاب معتبر نیست ('.$code.').',
            ];
        }

        $atelierId = (int) ($user->atelier_id ?? 0);
        if ($atelierId <= 0) {
            return ['ok' => false, 'final' => false, 'message' => 'هنوز فروشگاهی برای این کاربر ثبت نشده است.'];
        }
        // پرسنل معمولی نمی‌تواند claim کند؛ مالک/بدون نقش OK
        if (($user->shop_staff_role ?? null) === ShopStaffAccess::ROLE_STAFF) {
            return ['ok' => false, 'final' => true, 'message' => 'فقط مالک فروشگاه قابل انتساب است.'];
        }

        $existing = MarketerReferral::query()->where('atelier_id', $atelierId)->first();
        if ($existing) {
            return [
                'ok' => (int) $existing->marketer_id === (int) $marketer->id,
                'final' => true,
                'message' => (int) $existing->marketer_id === (int) $marketer->id
                    ? 'قبلاً به همین بازاریاب ثبت شده است.'
                    : 'این فروشگاه قبلاً به بازاریاب دیگری ثبت شده است.',
                'referral_id' => $existing->id,
            ];
        }

        $atelier = Atelier::query()->find($atelierId);
        if (! $atelier) {
            return ['ok' => false, 'final' => true, 'message' => 'فروشگاه یافت نشد.'];
        }

        $visit = null;
        if ($visitorId !== null && $visitorId !== '') {
            $visit = MarketerVisit::query()
                ->where('marketer_id', $marketer->id)
                ->where('visitor_id', $visitorId)
                ->orderBy('id')
                ->first();
        }

        try {
            $referral = MarketerReferral::query()->create([
                'marketer_id' => $marketer->id,
                'atelier_id' => $atelierId,
                'user_id' => $user->id,
                'visitor_id' => $visitorId ?: null,
                'first_visit_at' => $visit?->created_at,
            ]);
        } catch (QueryException $e) {
            \Log::error('marketer_referral_create_failed', [
                'message' => $e->getMessage(),
                'atelier_id' => $atelierId,
                'marketer_id' => $marketer->id,
            ]);
            // اگر همزمان ثبت شده
            $again = MarketerReferral::query()->where('atelier_id', $atelierId)->first();
            if ($again) {
                return [
                    'ok' => (int) $again->marketer_id === (int) $marketer->id,
                    'final' => true,
                    'message' => 'این فروشگاه قبلاً ثبت شده است.',
                    'referral_id' => $again->id,
                ];
            }

            return [
                'ok' => false,
                'final' => true,
                'message' => 'خطا در ذخیره انتساب: '.$e->getMessage(),
            ];
        }

        $this->notifyMarketerRegistration($marketer, $atelier, (string) $user->phone);
        try {
            $this->syncCommissions($marketer);
        } catch (\Throwable $e) {
            report($e);
        }

        return [
            'ok' => true,
            'final' => true,
            'message' => 'ثبت‌نام شما به نام معرف ثبت شد.',
            'referral_id' => $referral->id,
            'marketer_id' => $marketer->id,
            'atelier_id' => $atelierId,
        ];
    }

    /**
     * پرداخت‌های موفق پلن فروشگاه‌های معرفی‌شده را به سهم بازاریاب تبدیل می‌کند (idempotent).
     */
    public function syncCommissions(?Marketer $only = null): int
    {
        if (! Schema::hasTable('gateway_payments') || ! Schema::hasTable('marketer_commissions')) {
            return 0;
        }

        $rows = GatewayPayment::query()
            ->join('marketer_referrals as mr', 'mr.atelier_id', '=', 'gateway_payments.atelier_id')
            ->whereIn('gateway_payments.type', [
                GatewayPayment::TYPE_SHOP_PLAN,
                GatewayPayment::TYPE_SHOP_PACKAGE,
            ])
            ->where('gateway_payments.status', GatewayPayment::STATUS_PAID)
            ->when($only, fn ($q) => $q->where('mr.marketer_id', $only->id))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('marketer_commissions as mc')
                    ->whereColumn('mc.gateway_payment_id', 'gateway_payments.id');
            })
            ->select([
                'gateway_payments.id',
                'gateway_payments.atelier_id',
                'gateway_payments.amount_rial',
                'gateway_payments.description',
                'gateway_payments.paid_at',
                'gateway_payments.created_at',
                'mr.id as mr_id',
                'mr.marketer_id as mr_marketer_id',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $marketers = Marketer::query()
            ->whereIn('id', $rows->pluck('mr_marketer_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $atelierIds = $rows->pluck('atelier_id')->unique()->all();
        $ateliers = Atelier::query()->whereIn('id', $atelierIds)->get()->keyBy('id');

        $created = 0;
        foreach ($rows as $row) {
            $marketer = $marketers->get($row->mr_marketer_id);
            if (! $marketer) {
                continue;
            }
            $purchaseToman = intdiv((int) $row->amount_rial, 10);
            $percent = $marketer->effectiveCommissionPercent();
            $commissionToman = (int) round($purchaseToman * $percent / 100);

            $commission = MarketerCommission::query()->firstOrCreate(
                ['gateway_payment_id' => $row->id],
                [
                    'marketer_id' => $marketer->id,
                    'marketer_referral_id' => $row->mr_id,
                    'atelier_id' => $row->atelier_id,
                    'purchase_amount_toman' => $purchaseToman,
                    'percent' => $percent,
                    'commission_toman' => $commissionToman,
                    'description' => $row->description !== null ? mb_substr((string) $row->description, 0, 255) : null,
                    'purchased_at' => $row->paid_at ?? $row->created_at,
                ]
            );

            if (! $commission->wasRecentlyCreated) {
                continue;
            }

            $created++;
            $atelier = $ateliers->get($row->atelier_id);
            $this->notifyMarketerPurchase($marketer, $atelier, $commissionToman);
        }

        return $created;
    }

    /** همگام‌سازی پورسانت بعد از پرداخت موفق یک فروشگاه معرفی‌شده */
    public function syncCommissionsForAtelier(int $atelierId): int
    {
        if ($atelierId <= 0) {
            return 0;
        }
        $referral = MarketerReferral::query()->where('atelier_id', $atelierId)->first();
        if (! $referral) {
            return 0;
        }
        $marketer = Marketer::query()->find($referral->marketer_id);
        if (! $marketer) {
            return 0;
        }

        return $this->syncCommissions($marketer);
    }

    protected function notifyMarketerRegistration(Marketer $marketer, Atelier $atelier, ?string $userPhone = null): void
    {
        // خودمعرفی: رکورد ذخیره می‌شود ولی پیامک نمی‌رود
        if ($userPhone && self::normalizePhone($userPhone) === self::normalizePhone($marketer->phone)) {
            return;
        }
        $phone = self::normalizePhone($marketer->phone);
        if (! $phone) {
            return;
        }
        $shop = trim((string) $atelier->name) !== '' ? $atelier->name : 'فروشگاه';
        $text = "وبینو\nثبت‌نام جدید از لینک شما:\n{$shop}";
        try {
            SmsTools::sendSms($phone, $text);
        } catch (\Throwable) {
            //
        }
    }

    protected function notifyMarketerPurchase(
        Marketer $marketer,
        ?Atelier $atelier,
        int $commissionToman
    ): void {
        $phone = self::normalizePhone($marketer->phone);
        if (! $phone) {
            return;
        }
        $shop = $atelier && trim((string) $atelier->name) !== '' ? $atelier->name : 'فروشگاه';
        $commission = number_format(max(0, $commissionToman));
        $text = "وبینو\nخرید از معرفی شما:\n{$shop}\nپورسانت {$commission} ت";
        try {
            SmsTools::sendSms($phone, $text);
        } catch (\Throwable) {
            //
        }
    }

    /**
     * @param  list<int>|null  $marketerIds
     * @return Collection<int, array<string, int>>  کلید: marketer_id
     */
    public function summaries(?array $marketerIds = null): Collection
    {
        $scope = fn ($q) => $marketerIds === null ? $q : $q->whereIn('marketer_id', $marketerIds);

        $visits = $scope(MarketerVisit::query())
            ->select('marketer_id', DB::raw('COUNT(DISTINCT visitor_id) as c'))
            ->groupBy('marketer_id')
            ->pluck('c', 'marketer_id');

        $registered = $scope(MarketerReferral::query())
            ->select('marketer_id', DB::raw('COUNT(*) as c'))
            ->groupBy('marketer_id')
            ->pluck('c', 'marketer_id');

        $commissions = $scope(MarketerCommission::query())
            ->select(
                'marketer_id',
                DB::raw('COUNT(DISTINCT atelier_id) as paid_shops'),
                DB::raw('COUNT(*) as purchases'),
                DB::raw('SUM(purchase_amount_toman) as sales'),
                DB::raw('SUM(commission_toman) as earned')
            )
            ->groupBy('marketer_id')
            ->get()
            ->keyBy('marketer_id');

        $payouts = $scope(MarketerPayout::query())
            ->select('marketer_id', DB::raw('SUM(amount_toman) as paid'))
            ->groupBy('marketer_id')
            ->pluck('paid', 'marketer_id');

        $ids = collect($marketerIds ?? [])
            ->merge($visits->keys())
            ->merge($registered->keys())
            ->merge($commissions->keys())
            ->merge($payouts->keys())
            ->map(fn ($id) => (int) $id)
            ->unique();

        return $ids->mapWithKeys(function (int $id) use ($visits, $registered, $commissions, $payouts) {
            $c = $commissions->get($id);
            $earned = (int) ($c->earned ?? 0);
            $paid = (int) ($payouts[$id] ?? 0);

            return [$id => [
                'visitors_count' => (int) ($visits[$id] ?? 0),
                'registered_count' => (int) ($registered[$id] ?? 0),
                'paid_shops_count' => (int) ($c->paid_shops ?? 0),
                'purchases_count' => (int) ($c->purchases ?? 0),
                'total_sales_toman' => (int) ($c->sales ?? 0),
                'total_commission_toman' => $earned,
                'total_paid_toman' => $paid,
                'balance_toman' => $earned - $paid,
            ]];
        });
    }

    /** @return array<string, int> */
    public function summaryFor(Marketer $marketer): array
    {
        return $this->summaries([$marketer->id])->get($marketer->id, [
            'visitors_count' => 0,
            'registered_count' => 0,
            'paid_shops_count' => 0,
            'purchases_count' => 0,
            'total_sales_toman' => 0,
            'total_commission_toman' => 0,
            'total_paid_toman' => 0,
            'balance_toman' => 0,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function referralsFor(Marketer $marketer, bool $maskPhones): array
    {
        $referrals = MarketerReferral::query()
            ->with([
                'atelier:id,name,code,created_at,shop_access_ends_at',
                'user:id,name,last_name,phone',
                'commissions' => fn ($q) => $q->orderByDesc('purchased_at')->orderByDesc('id'),
            ])
            ->where('marketer_id', $marketer->id)
            ->orderByDesc('id')
            ->get();

        return $referrals->map(function (MarketerReferral $referral) use ($maskPhones) {
            $user = $referral->user;
            $atelier = $referral->atelier;
            $phone = $user?->phone;
            $commissions = $referral->commissions;

            return [
                'id' => $referral->id,
                'registered_at' => $referral->created_at?->format('Y-m-d H:i:s'),
                'first_visit_at' => $referral->first_visit_at?->format('Y-m-d H:i:s'),
                'shop_name' => $atelier?->name,
                'shop_code' => $maskPhones ? null : $atelier?->code,
                'owner_name' => $user ? trim(($user->name ?? '').' '.($user->last_name ?? '')) : null,
                'owner_phone' => $maskPhones ? self::maskPhone($phone) : $phone,
                'is_paid' => $commissions->isNotEmpty(),
                'purchases_count' => $commissions->count(),
                'total_sales_toman' => (int) $commissions->sum('purchase_amount_toman'),
                'total_commission_toman' => (int) $commissions->sum('commission_toman'),
                'last_purchase_at' => $commissions->first()?->purchased_at?->format('Y-m-d H:i:s'),
                'purchases' => $commissions->map(fn (MarketerCommission $c) => [
                    'id' => $c->id,
                    'purchased_at' => $c->purchased_at?->format('Y-m-d H:i:s'),
                    'description' => $c->description,
                    'purchase_amount_toman' => (int) $c->purchase_amount_toman,
                    'percent' => (float) $c->percent,
                    'commission_toman' => (int) $c->commission_toman,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function payoutsFor(Marketer $marketer): array
    {
        return MarketerPayout::query()
            ->where('marketer_id', $marketer->id)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MarketerPayout $p) => [
                'id' => $p->id,
                'amount_toman' => (int) $p->amount_toman,
                'note' => $p->note,
                'paid_at' => ($p->paid_at ?? $p->created_at)?->format('Y-m-d H:i:s'),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function formatMarketer(Marketer $marketer, bool $forAdmin): array
    {
        $data = [
            'id' => $marketer->id,
            'name' => $marketer->name,
            'phone' => $marketer->phone,
            'code' => $marketer->code,
            'referral_link' => $this->referralLink($marketer),
            'commission_percent' => $marketer->effectiveCommissionPercent(),
            'card_number' => $marketer->card_number,
            'sheba' => $marketer->sheba,
            'is_active' => (bool) $marketer->is_active,
            'created_at' => $marketer->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($forAdmin) {
            $data['custom_commission_percent'] = $marketer->commission_percent !== null
                ? (float) $marketer->commission_percent
                : null;
            $data['admin_note'] = $marketer->admin_note;
            $data['last_login_at'] = $marketer->last_login_at?->format('Y-m-d H:i:s');
        }

        return $data;
    }
}
