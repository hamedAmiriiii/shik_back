<?php

namespace App\Services\Billiard;

use App\Models\BilliardRegistration;
use App\Models\BilliardTournament;
use App\Models\BilliardUser;
use App\Models\GatewayPayment;
use App\Services\GatewayPaymentService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BilliardRegistrationService
{
    public function register(BilliardTournament $tournament, BilliardUser $player): BilliardRegistration
    {
        if (! $player->isProfileComplete()) {
            throw new RuntimeException('ابتدا پروفایل خود را کامل کنید (نام، نام خانوادگی و عکس الزامی است).');
        }
        if (! $tournament->acceptsRegistration()) {
            throw new RuntimeException('ثبت‌نام این مسابقه فعال نیست یا ظرفیت تکمیل شده است.');
        }

        $existing = BilliardRegistration::query()
            ->where('tournament_id', $tournament->id)
            ->where('player_id', $player->id)
            ->first();

        // فقط بعد از پرداخت/رایگان، ثبت‌نام قطعی است
        if ($existing && $existing->hasSettledPayment()
            && $existing->status !== BilliardRegistration::STATUS_CANCELLED
            && $existing->status !== BilliardRegistration::STATUS_REJECTED) {
            throw new RuntimeException('قبلاً در این مسابقه ثبت‌نام کرده‌اید.');
        }

        $fee = (int) $tournament->fee_toman;
        $free = $fee <= 0;

        return DB::transaction(function () use ($tournament, $player, $fee, $free, $existing) {
            // تا پرداخت نشده در مسابقه قطعی نمی‌شود؛ فقط پیش‌نویس برای درگاه
            $payload = [
                'tournament_id' => $tournament->id,
                'player_id' => $player->id,
                'status' => $free
                    ? BilliardRegistration::STATUS_CONFIRMED
                    : BilliardRegistration::STATUS_PENDING,
                'payment_status' => $free
                    ? BilliardRegistration::PAYMENT_WAIVED
                    : BilliardRegistration::PAYMENT_UNPAID,
                'fee_toman' => $fee,
                'confirmed_at' => $free ? now() : null,
                'paid_at' => null,
                'gateway_payment_id' => null,
                'payment_ref' => null,
            ];

            if ($existing) {
                // تلاش ناتمام / رد شده / لغو شده → از همان رکورد دوباره پرداخت
                $existing->update($payload);

                return $existing->fresh(['player', 'tournament']);
            }

            return BilliardRegistration::create($payload)->fresh(['player', 'tournament']);
        });
    }

    public function startPayment(
        BilliardRegistration $registration,
        BilliardUser $player,
        GatewayPaymentService $payments,
        ?string $returnUrl,
        ?string $gateway = null
    ): array {
        if ((int) $registration->player_id !== (int) $player->id) {
            throw new RuntimeException('ثبت‌نام متعلق به شما نیست.');
        }
        if ($registration->payment_status === BilliardRegistration::PAYMENT_PAID
            || $registration->payment_status === BilliardRegistration::PAYMENT_WAIVED) {
            throw new RuntimeException('پرداخت این ثبت‌نام لازم نیست یا انجام شده است.');
        }
        if ((int) $registration->fee_toman < 100) {
            throw new RuntimeException('مبلغ ثبت‌نام برای پرداخت آنلاین معتبر نیست.');
        }

        $payload = $payments->start(
            0,
            null,
            GatewayPayment::TYPE_BILLIARD_REGISTRATION,
            (int) $registration->id,
            $returnUrl,
            $player->phone,
            $gateway ?? GatewayPayment::GATEWAY_ZARINPAL
        );

        $registration->update(['payment_status' => BilliardRegistration::PAYMENT_PENDING]);

        return $payload;
    }

    public function fulfillOnlinePayment(GatewayPayment $payment, ?string $refId): void
    {
        $registration = BilliardRegistration::query()->find($payment->item_id);
        if (! $registration) {
            throw new RuntimeException('ثبت‌نام یافت نشد.');
        }

        $registration->update([
            'payment_status' => BilliardRegistration::PAYMENT_PAID,
            'gateway_payment_id' => $payment->id,
            'payment_ref' => $refId,
            'paid_at' => now(),
            // بعد از پرداخت موفق ثبت‌نام در مسابقه قطعی می‌شود
            'status' => BilliardRegistration::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    /**
     * افزودن دستی بازیکن توسط ادمین — بلافاصله در مسابقه قطعی می‌شود.
     * به‌صورت پیش‌فرض هزینه رایگان (waived) ثبت می‌شود.
     */
    public function adminAddPlayer(
        BilliardTournament $tournament,
        BilliardUser $player,
        bool $waiveFee = true
    ): BilliardRegistration {
        if ($player->isStaff()) {
            throw new RuntimeException('کاربر سیستمی را نمی‌توان به‌عنوان بازیکن اضافه کرد.');
        }
        if (! $player->is_active) {
            throw new RuntimeException('این بازیکن غیرفعال است.');
        }
        if (! $player->first_name || ! $player->last_name || ! $player->phone) {
            throw new RuntimeException('نام، نام خانوادگی و موبایل بازیکن الزامی است.');
        }
        if (in_array($tournament->status, [
            BilliardTournament::STATUS_FINISHED,
            BilliardTournament::STATUS_CANCELLED,
        ], true)) {
            throw new RuntimeException('این مسابقه پایان یافته یا لغو شده است.');
        }

        $existing = BilliardRegistration::query()
            ->where('tournament_id', $tournament->id)
            ->where('player_id', $player->id)
            ->first();

        if ($existing && $existing->isActiveInTournament()) {
            throw new RuntimeException('این بازیکن از قبل در مسابقه ثبت است.');
        }

        if ($tournament->capacity) {
            $count = $tournament->registrations()->activeInTournament()->count();
            if ($count >= (int) $tournament->capacity) {
                throw new RuntimeException('ظرفیت مسابقه تکمیل شده است.');
            }
        }

        $fee = (int) $tournament->fee_toman;
        $settled = $waiveFee || $fee <= 0;

        return DB::transaction(function () use ($tournament, $player, $fee, $settled, $existing) {
            $payload = [
                'tournament_id' => $tournament->id,
                'player_id' => $player->id,
                'status' => $settled
                    ? BilliardRegistration::STATUS_CONFIRMED
                    : BilliardRegistration::STATUS_PENDING,
                'payment_status' => $settled
                    ? BilliardRegistration::PAYMENT_WAIVED
                    : BilliardRegistration::PAYMENT_UNPAID,
                'fee_toman' => $fee,
                'confirmed_at' => $settled ? now() : null,
                'paid_at' => null,
                'gateway_payment_id' => null,
                'payment_ref' => null,
            ];

            if ($existing) {
                $existing->update($payload);

                return $existing->fresh(['player', 'tournament']);
            }

            return BilliardRegistration::create($payload)->fresh(['player', 'tournament']);
        });
    }

    public function confirm(BilliardRegistration $registration): BilliardRegistration
    {
        if (! $registration->hasSettledPayment()) {
            throw new RuntimeException('تا قبل از پرداخت، ثبت‌نام قابل تأیید نیست.');
        }

        $registration->update([
            'status' => BilliardRegistration::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);

        return $registration->fresh(['player', 'tournament']);
    }

    public function reject(BilliardRegistration $registration): BilliardRegistration
    {
        $registration->update(['status' => BilliardRegistration::STATUS_REJECTED]);

        return $registration->fresh(['player', 'tournament']);
    }
}
