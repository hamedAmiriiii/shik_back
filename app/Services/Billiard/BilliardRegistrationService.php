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
        if ($existing && $existing->status !== BilliardRegistration::STATUS_CANCELLED
            && $existing->status !== BilliardRegistration::STATUS_REJECTED) {
            throw new RuntimeException('قبلاً در این مسابقه ثبت‌نام کرده‌اید.');
        }

        $fee = (int) $tournament->fee_toman;

        return DB::transaction(function () use ($tournament, $player, $fee, $existing) {
            $payload = [
                'tournament_id' => $tournament->id,
                'player_id' => $player->id,
                'status' => BilliardRegistration::STATUS_PENDING,
                'payment_status' => $fee <= 0
                    ? BilliardRegistration::PAYMENT_WAIVED
                    : BilliardRegistration::PAYMENT_UNPAID,
                'fee_toman' => $fee,
            ];

            if ($existing) {
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
            // بعد از پرداخت موفق به‌صورت پیش‌فرض تأیید می‌شود؛ ادمین می‌تواند رد کند
            'status' => BilliardRegistration::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    public function confirm(BilliardRegistration $registration): BilliardRegistration
    {
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
