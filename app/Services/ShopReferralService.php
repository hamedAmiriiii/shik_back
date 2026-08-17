<?php

namespace App\Services;

use App\Models\Atelier;
use App\Models\ShopReferral;
use App\Models\User;
use App\Tools\SmsTools;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopReferralService
{
    public static function rewardAmount(): int
    {
        return max(0, (int) config('referral.reward_amount', 1_000_000));
    }

    public static function ensureReferralIdentity(User $user): User
    {
        $updates = [];

        if (! $user->referral_code) {
            $updates['referral_code'] = self::generateUniqueReferralCode();
        }
        if (! $user->referral_dashboard_token) {
            $updates['referral_dashboard_token'] = Str::random(48);
        }

        if ($updates !== []) {
            $user->update($updates);
            $user->refresh();
        }

        return $user;
    }

    public static function registerLinkFor(User $user): string
    {
        $user = self::ensureReferralIdentity($user);
        $base = rtrim((string) config('referral.frontend_register_url'), '/');

        return $base.'?ref='.$user->referral_code;
    }

    public static function dashboardLinkFor(User $user): string
    {
        $user = self::ensureReferralIdentity($user);
        $base = rtrim((string) config('referral.frontend_dashboard_url'), '/');

        return $base.'/'.($user->phone ?: $user->referral_dashboard_token);
    }

    public static function apiDashboardUrl(User $user): string
    {
        $user = self::ensureReferralIdentity($user);

        return url(self::dashboardPathFor($user));
    }

    /**
     * ثبت معرف هنگام ثبت‌نام فروشگاه جدید.
     */
    public static function attachReferrerOnShopRegistration(User $newOwner, Atelier $atelier, ?string $referralCode): void
    {
        $referralCode = self::normalizeReferralCode($referralCode);
        if ($referralCode === null) {
            return;
        }

        $referrer = User::query()
            ->where('referral_code', $referralCode)
            ->first();

        if (! $referrer || (int) $referrer->id === (int) $newOwner->id) {
            return;
        }

        $atelier->update(['referred_by_user_id' => $referrer->id]);

        ShopReferral::query()->updateOrCreate(
            ['referred_atelier_id' => $atelier->id],
            [
                'referrer_user_id' => $referrer->id,
                'referred_user_id' => $newOwner->id,
                'status' => ShopReferral::STATUS_REGISTERED,
                'registered_at' => now(),
            ]
        );
    }

    /**
     * فعال‌سازی پلن پولی فروشگاه و پرداخت پاداش به معرف (یک‌بار).
     */
    public static function onPaidPlanActivated(Atelier $atelier): void
    {
        if ($atelier->subscription_status === Atelier::SUBSCRIPTION_PAID && $atelier->paid_plan_activated_at) {
            return;
        }

        DB::transaction(function () use ($atelier) {
            $locked = Atelier::query()->where('id', $atelier->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            if ($locked->subscription_status === Atelier::SUBSCRIPTION_PAID && $locked->paid_plan_activated_at) {
                return;
            }

            $locked->update([
                'subscription_status' => Atelier::SUBSCRIPTION_PAID,
                'paid_plan_activated_at' => now(),
            ]);

            $referral = ShopReferral::query()
                ->where('referred_atelier_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if (! $referral || $referral->isRewarded()) {
                return;
            }

            $rewardAmount = self::rewardAmount();
            $referral->update([
                'status' => ShopReferral::STATUS_REWARDED,
                'plan_activated_at' => now(),
                'reward_amount' => $rewardAmount,
                'rewarded_at' => now(),
            ]);

            if ($rewardAmount <= 0) {
                return;
            }

            $referrer = User::query()->where('id', $referral->referrer_user_id)->lockForUpdate()->first();
            if (! $referrer) {
                return;
            }

            $referrer->update([
                'referral_balance' => round((float) $referrer->referral_balance + $rewardAmount, 2),
            ]);

            self::notifyReferrerOfReward($referrer, $locked, $rewardAmount);
        });
    }

    public static function statsFor(User $user): array
    {
        $user = self::ensureReferralIdentity($user);

        $registeredCount = ShopReferral::query()
            ->where('referrer_user_id', $user->id)
            ->count();

        $paidCount = ShopReferral::query()
            ->where('referrer_user_id', $user->id)
            ->whereIn('status', [ShopReferral::STATUS_PLAN_ACTIVATED, ShopReferral::STATUS_REWARDED])
            ->count();

        $rewardedCount = ShopReferral::query()
            ->where('referrer_user_id', $user->id)
            ->where('status', ShopReferral::STATUS_REWARDED)
            ->count();

        $totalRewards = ShopReferral::query()
            ->where('referrer_user_id', $user->id)
            ->where('status', ShopReferral::STATUS_REWARDED)
            ->sum('reward_amount');

        return [
            'registered_count' => $registeredCount,
            'paid_count' => $paidCount,
            'rewarded_count' => $rewardedCount,
            'total_rewards_earned' => round((float) $totalRewards, 2),
            'referral_balance' => round((float) $user->referral_balance, 2),
            'reward_per_activation' => self::rewardAmount(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function referralsListFor(User $user): array
    {
        return ShopReferral::query()
            ->with(['referredUser:id,name,last_name,phone', 'referredAtelier:id,name,code,subscription_status,paid_plan_activated_at'])
            ->where('referrer_user_id', $user->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ShopReferral $referral) => self::formatReferralRow($referral))
            ->all();
    }

    public static function findReferrerByDashboardToken(string $token): ?User
    {
        return self::findReferrerByPublicIdentifier($token);
    }

    /**
     * پیدا کردن معرف با شماره موبایل، کد معرف، یا توکن داشبورد.
     */
    public static function findReferrerByPublicIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (preg_match('/^09\d{9}$/', $identifier)) {
            return User::query()->where('phone', $identifier)->first();
        }

        $normalizedPhone = preg_replace('/\D/', '', $identifier);
        if (strlen($normalizedPhone) === 11 && str_starts_with($normalizedPhone, '09')) {
            return User::query()->where('phone', $normalizedPhone)->first();
        }

        if (strlen($identifier) <= 16) {
            $byCode = User::query()
                ->where('referral_code', strtoupper($identifier))
                ->first();
            if ($byCode) {
                return $byCode;
            }
        }

        return User::query()->where('referral_dashboard_token', $identifier)->first();
    }

    public static function dashboardPathFor(User $user): string
    {
        $user = self::ensureReferralIdentity($user);

        return '/api/referrals/'.($user->phone ?: $user->referral_dashboard_token);
    }

    public static function formatReferralRow(ShopReferral $referral): array
    {
        $referredUser = $referral->referredUser;
        $atelier = $referral->referredAtelier;

        return [
            'id' => $referral->id,
            'status' => $referral->status,
            'status_label' => self::statusLabel($referral->status),
            'registered_at' => $referral->registered_at?->format('Y-m-d H:i:s'),
            'plan_activated_at' => $referral->plan_activated_at?->format('Y-m-d H:i:s'),
            'rewarded_at' => $referral->rewarded_at?->format('Y-m-d H:i:s'),
            'reward_amount' => $referral->reward_amount !== null ? (float) $referral->reward_amount : null,
            'referred_user' => $referredUser ? [
                'name' => trim($referredUser->name.' '.$referredUser->last_name),
                'phone' => $referredUser->phone,
            ] : null,
            'shop' => $atelier ? [
                'name' => $atelier->name,
                'code' => $atelier->code,
                'subscription_status' => $atelier->subscription_status,
                'subscription_status_label' => Atelier::subscriptionStatusLabel($atelier->subscription_status),
                'is_paid' => $atelier->subscription_status === Atelier::SUBSCRIPTION_PAID,
            ] : null,
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            ShopReferral::STATUS_REGISTERED => 'ثبت‌نام شده',
            ShopReferral::STATUS_PLAN_ACTIVATED => 'پلن فعال شده',
            ShopReferral::STATUS_REWARDED => 'پاداش پرداخت شده',
            default => $status,
        };
    }

    protected static function normalizeReferralCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = strtoupper(trim($code));

        return $code !== '' ? $code : null;
    }

    protected static function generateUniqueReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (User::query()->where('referral_code', $code)->exists());

        return $code;
    }

    protected static function notifyReferrerOfReward(User $referrer, Atelier $referredShop, int $rewardAmount): void
    {
        if (! $referrer->phone) {
            return;
        }

        $formatted = number_format($rewardAmount, 0);
        $text = "پاداش معرفی\nفروشگاه «{$referredShop->name}» پلن خریداری کرد. مبلغ {$formatted} تومان به حساب معرفی شما اضافه شد.";

        try {
            SmsTools::sendSms($referrer->phone, $text);
        } catch (\Throwable) {
            //
        }
    }
}
