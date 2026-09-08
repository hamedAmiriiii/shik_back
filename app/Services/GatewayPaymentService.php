<?php

namespace App\Services;

use App\Models\Atelier;
use App\Models\GatewayPayment;
use App\Models\SmsPackage;
use App\Models\SmsPackageOrder;
use App\Models\ShopPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class GatewayPaymentService
{
    public function __construct(protected ZarinpalClient $zarinpal)
    {
    }

    /**
     * @return array{sms_packages: array<int, mixed>, shop_plans: array<int, mixed>}
     */
    public function catalog(): array
    {
        $sms = [];
        if (Schema::hasTable('sms_packages')) {
            $sms = SmsPackage::query()->active()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (SmsPackage $p) => $this->formatSmsPackage($p))
                ->all();
        }

        $plans = [];
        if (Schema::hasTable('shop_plans')) {
            $plans = ShopPlan::query()->active()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (ShopPlan $p) => $this->formatShopPlan($p))
                ->all();
        }

        return [
            'sms_packages' => $sms,
            'shop_plans' => $plans,
            'currency' => 'IRR',
            'gateway' => 'zarinpal',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function start(int $atelierId, ?int $userId, string $type, int $itemId, ?string $returnUrl, ?string $mobile = null): array
    {
        if (! Schema::hasTable('gateway_payments')) {
            throw new RuntimeException('جدول پرداخت ساخته نشده است. SQL درگاه را اجرا کنید.');
        }

        [$amount, $description, $meta] = $this->resolveItem($type, $itemId);
        if ($amount < 1000) {
            throw new RuntimeException('مبلغ این آیتم برای پرداخت آنلاین معتبر نیست.');
        }

        $payment = GatewayPayment::create([
            'atelier_id' => $atelierId,
            'user_id' => $userId,
            'type' => $type,
            'item_id' => $itemId,
            'amount_rial' => $amount,
            'description' => $description,
            'status' => GatewayPayment::STATUS_PENDING,
            'gateway' => GatewayPayment::GATEWAY_ZARINPAL,
            'return_url' => $this->sanitizeReturnUrl($returnUrl),
            'meta' => $meta,
        ]);

        $callback = rtrim((string) (config('zarinpal.callback_url') ?: url('/api/payments/zarinpal/callback')), '/');
        $metadata = [];
        if (is_string($mobile) && $mobile !== '') {
            $metadata['mobile'] = $mobile;
        }

        $authority = $this->zarinpal->request(
            $amount,
            $callback.'?pid='.$payment->id,
            $description,
            $metadata
        );

        $payment->update(['authority' => $authority]);

        return $this->formatPayment($payment->fresh(), $this->zarinpal->startPayUrl($authority));
    }

    /**
     * @return array{ok: bool, payment: array<string, mixed>, redirect: string}
     */
    public function handleCallback(?string $authority, ?string $status, ?int $paymentId): array
    {
        $payment = null;
        if (is_string($authority) && $authority !== '') {
            $payment = GatewayPayment::query()->where('authority', $authority)->first();
        }
        if (! $payment && $paymentId) {
            $payment = GatewayPayment::query()->find($paymentId);
        }
        if (! $payment) {
            return [
                'ok' => false,
                'payment' => [],
                'redirect' => $this->redirectUrl(null, false, 'پرداخت یافت نشد.'),
            ];
        }

        if ($payment->isPaid()) {
            return [
                'ok' => true,
                'payment' => $this->formatPayment($payment),
                'redirect' => $this->redirectUrl($payment, true),
            ];
        }

        if (strtoupper((string) $status) !== 'OK') {
            $payment->update(['status' => GatewayPayment::STATUS_CANCELED]);

            return [
                'ok' => false,
                'payment' => $this->formatPayment($payment),
                'redirect' => $this->redirectUrl($payment, false, 'پرداخت لغو شد.'),
            ];
        }

        try {
            $verified = $this->zarinpal->verify((string) $payment->authority, (int) $payment->amount_rial);
            $this->fulfill($payment, $verified['ref_id'] ?? null, $verified);
        } catch (RuntimeException $e) {
            $payment->update(['status' => GatewayPayment::STATUS_FAILED]);

            return [
                'ok' => false,
                'payment' => $this->formatPayment($payment->fresh()),
                'redirect' => $this->redirectUrl($payment, false, $e->getMessage()),
            ];
        }

        return [
            'ok' => true,
            'payment' => $this->formatPayment($payment->fresh()),
            'redirect' => $this->redirectUrl($payment->fresh(), true),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function statusForAtelier(int $atelierId, string $authority): ?array
    {
        $payment = GatewayPayment::query()
            ->where('atelier_id', $atelierId)
            ->where('authority', $authority)
            ->first();

        return $payment ? $this->formatPayment($payment) : null;
    }

    /**
     * @param  array<string, mixed>  $verified
     */
    public function fulfill(GatewayPayment $payment, ?string $refId, array $verified = []): GatewayPayment
    {
        return DB::transaction(function () use ($payment, $refId, $verified) {
            /** @var GatewayPayment $locked */
            $locked = GatewayPayment::query()->where('id', $payment->id)->lockForUpdate()->first();
            if ($locked->isPaid()) {
                return $locked;
            }

            if ($locked->type === GatewayPayment::TYPE_SMS_PACKAGE) {
                $this->fulfillSms($locked);
            } elseif ($locked->type === GatewayPayment::TYPE_SHOP_PLAN) {
                $this->fulfillShopPlan($locked);
            } else {
                throw new RuntimeException('نوع خرید پشتیبانی نمی‌شود.');
            }

            $meta = $locked->meta ?? [];
            $meta['zarinpal'] = [
                'ref_id' => $refId,
                'card_pan' => $verified['card_pan'] ?? null,
            ];
            $locked->update([
                'status' => GatewayPayment::STATUS_PAID,
                'ref_id' => $refId,
                'paid_at' => now(),
                'meta' => $meta,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function formatPayment(GatewayPayment $payment, ?string $paymentUrl = null): array
    {
        $row = [
            'id' => $payment->id,
            'type' => $payment->type,
            'item_id' => $payment->item_id,
            'amount_rial' => (int) $payment->amount_rial,
            'amount_toman' => (int) floor(((int) $payment->amount_rial) / 10),
            'description' => $payment->description,
            'authority' => $payment->authority,
            'ref_id' => $payment->ref_id,
            'status' => $payment->status,
            'gateway' => $payment->gateway,
            'paid_at' => $payment->paid_at,
        ];
        if ($paymentUrl) {
            $row['payment_url'] = $paymentUrl;
        }

        return $row;
    }

    public function formatSmsPackage(SmsPackage $package): array
    {
        return [
            'id' => $package->id,
            'type' => GatewayPayment::TYPE_SMS_PACKAGE,
            'name' => $package->name,
            'sms_count' => $package->sms_count,
            'price_rial' => $package->price_rial,
            'price_toman' => $package->price_rial !== null ? (int) floor($package->price_rial / 10) : null,
            'sort_order' => $package->sort_order,
        ];
    }

    public function formatShopPlan(ShopPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'type' => GatewayPayment::TYPE_SHOP_PLAN,
            'name' => $plan->name,
            'duration_days' => $plan->duration_days,
            'price_rial' => $plan->price_rial,
            'price_toman' => (int) floor($plan->price_rial / 10),
            'sort_order' => $plan->sort_order,
        ];
    }

    /**
     * @return array{0: int, 1: string, 2: array<string, mixed>}
     */
    protected function resolveItem(string $type, int $itemId): array
    {
        if ($type === GatewayPayment::TYPE_SMS_PACKAGE) {
            $package = SmsPackage::query()->active()->find($itemId);
            if (! $package) {
                throw new RuntimeException('بسته پیامکی یافت نشد.');
            }

            return [
                (int) $package->price_rial,
                'خرید بسته پیامکی '.$package->name,
                ['sms_count' => $package->sms_count, 'name' => $package->name],
            ];
        }

        if ($type === GatewayPayment::TYPE_SHOP_PLAN) {
            $plan = ShopPlan::query()->active()->find($itemId);
            if (! $plan) {
                throw new RuntimeException('پلن اکانت یافت نشد.');
            }

            return [
                (int) $plan->price_rial,
                'خرید اکانت '.$plan->name,
                ['duration_days' => $plan->duration_days, 'name' => $plan->name],
            ];
        }

        throw new RuntimeException('نوع خرید نامعتبر است. sms_package یا shop_plan بفرستید.');
    }

    protected function fulfillSms(GatewayPayment $payment): void
    {
        $count = (int) ($payment->meta['sms_count'] ?? 0);
        if ($count <= 0) {
            $package = SmsPackage::query()->find($payment->item_id);
            $count = $package ? (int) $package->sms_count : 0;
        }
        if ($count <= 0) {
            throw new RuntimeException('تعداد پیامک بسته نامعتبر است.');
        }

        ShopSmsQuotaService::charge((int) $payment->atelier_id, $count);

        SmsPackageOrder::create([
            'atelier_id' => $payment->atelier_id,
            'sms_package_id' => $payment->item_id,
            'sms_count' => $count,
            'price_rial' => $payment->amount_rial,
            'status' => SmsPackageOrder::STATUS_APPROVED,
            'requested_by_user_id' => $payment->user_id,
            'reviewed_at' => now(),
            'admin_note' => 'پرداخت زرین‌پال'.($payment->authority ? ' / '.$payment->authority : ''),
        ]);
    }

    protected function fulfillShopPlan(GatewayPayment $payment): void
    {
        $days = (int) ($payment->meta['duration_days'] ?? 0);
        if ($days <= 0) {
            $plan = ShopPlan::query()->find($payment->item_id);
            $days = $plan ? (int) $plan->duration_days : 0;
        }
        if ($days <= 0) {
            throw new RuntimeException('مدت پلن نامعتبر است.');
        }

        $atelier = Atelier::query()->where('id', $payment->atelier_id)->lockForUpdate()->first();
        if (! $atelier) {
            throw new RuntimeException('فروشگاه یافت نشد.');
        }

        $from = $atelier->shop_access_ends_at && $atelier->shop_access_ends_at->isFuture()
            ? $atelier->shop_access_ends_at
            : now();
        $atelier->shop_access_ends_at = $from->copy()->addDays($days);
        $atelier->shop_access_suspended = false;
        if (! $atelier->shop_access_starts_at) {
            $atelier->shop_access_starts_at = now();
        }
        $atelier->save();

        ShopReferralService::onPaidPlanActivated($atelier->fresh());
    }

    protected function sanitizeReturnUrl(?string $url): string
    {
        $fallback = (string) config('zarinpal.frontend_return_url');
        if (! is_string($url) || trim($url) === '') {
            return $fallback;
        }
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $allowed = config('zarinpal.allowed_return_hosts', []);
        if (! is_string($host) || ! in_array($host, $allowed, true)) {
            return $fallback;
        }

        return $url;
    }

    protected function redirectUrl(?GatewayPayment $payment, bool $ok, ?string $message = null): string
    {
        $base = $payment && $payment->return_url
            ? $payment->return_url
            : (string) config('zarinpal.frontend_return_url');
        $parts = parse_url($base) ?: [];
        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['payment'] = $ok ? 'ok' : 'failed';
        if ($payment) {
            $query['authority'] = $payment->authority;
            $query['type'] = $payment->type;
            $query['item_id'] = $payment->item_id;
            if ($payment->ref_id) {
                $query['ref_id'] = $payment->ref_id;
            }
        }
        if ($message) {
            $query['message'] = $message;
        }

        $url = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'localhost');
        if (! empty($parts['port'])) {
            $url .= ':'.$parts['port'];
        }
        $url .= $parts['path'] ?? '/';
        $url .= '?'.http_build_query($query);
        if (! empty($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }
}
