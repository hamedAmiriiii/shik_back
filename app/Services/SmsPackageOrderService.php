<?php

namespace App\Services;

use App\Models\Atelier;
use App\Models\SmsPackage;
use App\Models\SmsPackageOrder;
use App\Models\User;
use App\Tools\SmsTools;
use Illuminate\Support\Facades\DB;

class SmsPackageOrderService
{
    public static function createOrder(int $atelierId, SmsPackage $package, ?int $requestedByUserId): SmsPackageOrder
    {
        if (! $package->is_active) {
            abort(response()->json(['message' => 'این بسته در حال حاضر فعال نیست.'], 422));
        }

        $hasPending = SmsPackageOrder::query()
            ->where('atelier_id', $atelierId)
            ->where('sms_package_id', $package->id)
            ->where('status', SmsPackageOrder::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            abort(response()->json([
                'message' => 'درخواست خرید این بسته در انتظار تأیید است. لطفاً تا بررسی ادمین صبر کنید.',
            ], 422));
        }

        $order = SmsPackageOrder::create([
            'atelier_id' => $atelierId,
            'sms_package_id' => $package->id,
            'sms_count' => (int) $package->sms_count,
            'price_rial' => $package->price_rial,
            'status' => SmsPackageOrder::STATUS_PENDING,
            'requested_by_user_id' => $requestedByUserId,
        ]);

        self::notifyAdminsOfNewOrder($order);

        return $order->fresh(['smsPackage', 'atelier']);
    }

    public static function approve(SmsPackageOrder $order, User $admin, ?string $adminNote = null): SmsPackageOrder
    {
        if (! $order->isPending()) {
            abort(response()->json(['message' => 'این درخواست قبلاً بررسی شده است.'], 422));
        }

        DB::transaction(function () use ($order, $admin, $adminNote) {
            $locked = SmsPackageOrder::query()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->isPending()) {
                abort(response()->json(['message' => 'این درخواست قبلاً بررسی شده است.'], 422));
            }

            ShopSmsQuotaService::charge((int) $locked->atelier_id, (int) $locked->sms_count);

            $locked->update([
                'status' => SmsPackageOrder::STATUS_APPROVED,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
                'admin_note' => $adminNote,
            ]);
        });

        $order->refresh();
        self::notifyShopOfApproval($order);

        return $order->load(['smsPackage', 'atelier', 'requestedBy', 'reviewedBy']);
    }

    public static function reject(SmsPackageOrder $order, User $admin, ?string $adminNote = null): SmsPackageOrder
    {
        if (! $order->isPending()) {
            abort(response()->json(['message' => 'این درخواست قبلاً بررسی شده است.'], 422));
        }

        $order->update([
            'status' => SmsPackageOrder::STATUS_REJECTED,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
            'admin_note' => $adminNote,
        ]);

        self::notifyShopOfRejection($order);

        return $order->load(['smsPackage', 'atelier', 'requestedBy', 'reviewedBy']);
    }

    protected static function notifyAdminsOfNewOrder(SmsPackageOrder $order): void
    {
        $atelier = Atelier::find($order->atelier_id);
        $shopName = $atelier ? $atelier->name : 'فروشگاه';

        $text = "درخواست خرید بسته پیامکی ({$order->sms_count} عدد) از فروشگاه «{$shopName}» ثبت شد. شماره درخواست: {$order->id}";

        $adminPhones = User::query()
            ->whereHas('roles', function ($q) {
                $q->where('id', User::USER_TYPE_KEY['ادمین']);
            })
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->pluck('phone')
            ->unique()
            ->all();

        foreach ($adminPhones as $phone) {
            SmsTools::sendSms($phone, $text);
        }
    }

    protected static function notifyShopOfApproval(SmsPackageOrder $order): void
    {
        $phone = self::resolveShopOwnerPhone((int) $order->atelier_id);
        if (! $phone) {
            return;
        }

        $newBalance = ShopSmsQuotaService::getBalance((int) $order->atelier_id);
        $text = "درخواست خرید بسته {$order->sms_count} پیامکی شما تأیید شد. موجودی جدید: {$newBalance} پیامک.";

        SmsTools::sendSms($phone, $text);
    }

    protected static function notifyShopOfRejection(SmsPackageOrder $order): void
    {
        $phone = self::resolveShopOwnerPhone((int) $order->atelier_id);
        if (! $phone) {
            return;
        }

        $text = "درخواست خرید بسته {$order->sms_count} پیامکی شما رد شد.";
        if ($order->admin_note) {
            $text .= ' دلیل: '.$order->admin_note;
        }

        SmsTools::sendSms($phone, $text);
    }

    protected static function resolveShopOwnerPhone(int $atelierId): ?string
    {
        $phone = User::query()
            ->where('atelier_id', $atelierId)
            ->whereHas('roles', function ($q) {
                $q->where('id', User::USER_TYPE_KEY['فروشگاه']);
            })
            ->orderBy('id')
            ->value('phone');

        return is_string($phone) && $phone !== '' ? $phone : null;
    }
}
