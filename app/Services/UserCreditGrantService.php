<?php

namespace App\Services;

use App\Models\UserCreditGrant;
use App\Models\UserShiksho;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class UserCreditGrantService
{
    public static function recordManualChange(
        int $atelierId,
        string $phone,
        string $creditType,
        float $oldAmount,
        float $newAmount
    ): void {
        $delta = round($newAmount - $oldAmount, 2);
        if (abs($delta) < 0.01 || ! self::tableExists()) {
            return;
        }

        $payload = [
            'atelier_id' => $atelierId,
            'phone' => $phone,
            'credit_type' => $creditType,
            'amount' => $delta,
            'source' => UserCreditGrant::SOURCE_MANUAL,
        ];
        if (self::expiryColumnsReady() && $delta > 0) {
            $payload['remaining'] = $delta;
        }

        $grant = UserCreditGrant::create($payload);

        if ($delta > 0 && $creditType === UserCreditGrant::TYPE_REGULAR) {
            CustomerCreditExpenseService::recordManualGrant(
                $atelierId,
                $phone,
                $delta,
                (int) $grant->id
            );
        }
    }

    public static function recordCampaignGrant(
        int $atelierId,
        string $phone,
        float $amount,
        ?Carbon $expiresAt = null,
        ?int $campaignId = null
    ): void {
        if ($amount < 0.01 || ! self::tableExists()) {
            return;
        }

        $payload = [
            'atelier_id' => $atelierId,
            'phone' => $phone,
            'credit_type' => UserCreditGrant::TYPE_REGULAR,
            'amount' => $amount,
            'source' => UserCreditGrant::SOURCE_CAMPAIGN,
        ];
        if (self::expiryColumnsReady()) {
            $payload['remaining'] = $amount;
            $payload['expires_at'] = $expiresAt;
            if (Schema::hasColumn('user_credit_grants', 'campaign_id')) {
                $payload['campaign_id'] = $campaignId;
            }
        }

        UserCreditGrant::create($payload);
    }

    /**
     * اعتبار کمپین منقضی‌شده را از کیف پول کم می‌کند.
     */
    public static function expireLapsedForUser(UserShiksho $user): float
    {
        if (! self::expiryColumnsReady() || ! $user->phone) {
            return 0.0;
        }

        $now = Carbon::now('Asia/Tehran');
        $grants = UserCreditGrant::query()
            ->where('atelier_id', (int) $user->atelier_id)
            ->where('phone', (string) $user->phone)
            ->where('credit_type', UserCreditGrant::TYPE_REGULAR)
            ->where('source', UserCreditGrant::SOURCE_CAMPAIGN)
            ->where('remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->get();

        $cut = 0.0;
        foreach ($grants as $grant) {
            $part = round(min((float) $grant->remaining, max(0, (float) $user->credit - $cut)), 2);
            $grant->remaining = 0;
            $grant->save();
            if ($part >= 0.01) {
                $cut += $part;
            }
        }

        if ($cut >= 0.01) {
            $user->credit = max(0, round((float) $user->credit - $cut, 2));
            $user->save();
        }

        return $cut;
    }

    public static function availableRegularCredit(UserShiksho $user): float
    {
        self::expireLapsedForUser($user);
        $user->refresh();

        return max(0, (float) $user->credit);
    }

    /**
     * بعد از کم شدن از کیف پول، باقیمانده گرنت کمپین را هم کم می‌کند (اول آنهایی که زودتر تمام می‌شوند).
     */
    public static function consumeRemaining(UserShiksho $user, float $amount): void
    {
        if ($amount < 0.01 || ! self::expiryColumnsReady()) {
            return;
        }

        $left = round($amount, 2);
        $now = Carbon::now('Asia/Tehran');
        $grants = UserCreditGrant::query()
            ->where('atelier_id', (int) $user->atelier_id)
            ->where('phone', (string) $user->phone)
            ->where('credit_type', UserCreditGrant::TYPE_REGULAR)
            ->where('source', UserCreditGrant::SOURCE_CAMPAIGN)
            ->where('remaining', '>', 0)
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            })
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_at')
            ->get();

        foreach ($grants as $grant) {
            if ($left < 0.01) {
                break;
            }
            $take = round(min((float) $grant->remaining, $left), 2);
            $grant->remaining = round((float) $grant->remaining - $take, 2);
            $grant->save();
            $left = round($left - $take, 2);
        }
    }

    public static function sumManualGrantsInRange(int $atelierId, string $start, string $end): float
    {
        if (! self::tableExists()) {
            return 0.0;
        }

        return (float) UserCreditGrant::query()
            ->where('atelier_id', $atelierId)
            ->where('source', UserCreditGrant::SOURCE_MANUAL)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');
    }

    public static function expireLapsedForAtelier(?int $atelierId = null): int
    {
        if (! self::expiryColumnsReady()) {
            return 0;
        }

        $q = UserCreditGrant::query()
            ->where('source', UserCreditGrant::SOURCE_CAMPAIGN)
            ->where('remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now('Asia/Tehran'));
        if ($atelierId) {
            $q->where('atelier_id', $atelierId);
        }

        $phones = $q->get(['atelier_id', 'phone'])->unique(fn ($g) => $g->atelier_id.'|'.$g->phone);
        $n = 0;
        foreach ($phones as $row) {
            $user = UserShiksho::query()
                ->where('atelier_id', $row->atelier_id)
                ->where('phone', $row->phone)
                ->first();
            if ($user) {
                self::expireLapsedForUser($user);
                $n++;
            }
        }

        return $n;
    }

    protected static function expiryColumnsReady(): bool
    {
        try {
            return self::tableExists()
                && Schema::hasColumn('user_credit_grants', 'remaining')
                && Schema::hasColumn('user_credit_grants', 'expires_at');
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected static function tableExists(): bool
    {
        try {
            return Schema::hasTable('user_credit_grants');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
