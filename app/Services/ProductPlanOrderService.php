<?php

namespace App\Services;

use App\Models\ProductPlan;
use App\Models\ProductPlanOrder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProductPlanOrderService
{
    public function __construct(protected ZarinpalClient $zarinpal)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function start(int $planId, string $email, string $phone, ?string $returnUrl = null): array
    {
        if (! Schema::hasTable('product_plan_orders')) {
            throw new RuntimeException('جدول سفارش پلن ساخته نشده است. SQL را اجرا کنید.');
        }

        $plan = ProductPlan::query()->active()->find($planId);
        if (! $plan) {
            throw new RuntimeException('پلن یافت نشد.');
        }
        if (! in_array((int) $plan->duration_days, [180, 365], true)) {
            throw new RuntimeException('فقط پلن شش‌ماهه و یک‌ساله قابل خرید است.');
        }
        $amount = (int) $plan->price_rial;
        if ($amount < 1000) {
            throw new RuntimeException('مبلغ این پلن برای پرداخت آنلاین معتبر نیست.');
        }

        $phone = $this->normalizePhone($phone);
        $email = mb_strtolower(trim($email));
        $label = trim((string) ($plan->duration_label ?: $plan->duration_days.' روز'));
        $description = 'خرید '.$plan->name.' — '.$label;

        $order = ProductPlanOrder::create([
            'product_plan_id' => $plan->id,
            'product_slug' => $plan->product_slug,
            'email' => $email,
            'phone' => $phone,
            'amount_rial' => $amount,
            'description' => $description,
            'status' => ProductPlanOrder::STATUS_PENDING,
            'gateway' => 'zarinpal',
            'return_url' => $this->sanitizeReturnUrl($returnUrl),
            'meta' => [
                'name' => $plan->name,
                'max_users' => $plan->max_users,
                'duration_days' => $plan->duration_days,
                'duration_label' => $plan->duration_label,
            ],
        ]);

        $callback = rtrim((string) (config('zarinpal.callback_url') ?: url('/api/payments/zarinpal/callback')), '/');
        $authority = $this->zarinpal->request(
            $amount,
            $callback.'?oid='.$order->id,
            $description,
            ['mobile' => $phone, 'email' => $email]
        );
        $order->update(['authority' => $authority]);

        return [
            'id' => $order->id,
            'authority' => $authority,
            'payment_url' => $this->zarinpal->startPayUrl($authority),
            'amount_toman' => (int) floor($amount / 10),
            'email' => $email,
            'phone' => $phone,
        ];
    }

    /**
     * @return array{ok: bool, redirect: string}
     */
    public function handleCallback(?string $authority, ?string $status, ?int $orderId): array
    {
        $order = null;
        if (is_string($authority) && $authority !== '') {
            $order = ProductPlanOrder::query()->where('authority', $authority)->first();
        }
        if (! $order && $orderId) {
            $order = ProductPlanOrder::query()->find($orderId);
        }
        if (! $order) {
            return ['ok' => false, 'redirect' => $this->redirectUrl(null, false, 'سفارش یافت نشد.')];
        }
        if ($order->isPaid()) {
            return ['ok' => true, 'redirect' => $this->redirectUrl($order, true)];
        }
        if (strtoupper((string) $status) !== 'OK') {
            $order->update(['status' => ProductPlanOrder::STATUS_CANCELED]);

            return ['ok' => false, 'redirect' => $this->redirectUrl($order, false, 'پرداخت لغو شد.')];
        }

        try {
            $verified = $this->zarinpal->verify((string) $order->authority, (int) $order->amount_rial);
            $meta = $order->meta ?: [];
            $meta['zarinpal'] = $verified;
            $order->update([
                'status' => ProductPlanOrder::STATUS_PAID,
                'ref_id' => $verified['ref_id'] ?? null,
                'paid_at' => now(),
                'meta' => $meta,
            ]);
        } catch (RuntimeException $e) {
            $order->update(['status' => ProductPlanOrder::STATUS_FAILED]);

            return ['ok' => false, 'redirect' => $this->redirectUrl($order, false, $e->getMessage())];
        }

        return ['ok' => true, 'redirect' => $this->redirectUrl($order, true)];
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    /**
     * @return array<string, mixed>
     */
    public function format(ProductPlanOrder $order): array
    {
        $plan = $order->plan;
        $meta = is_array($order->meta) ? $order->meta : [];

        return [
            'id' => $order->id,
            'product' => $order->product_slug,
            'plan_id' => $order->product_plan_id,
            'plan_name' => $plan->name ?? ($meta['name'] ?? 'پلن'),
            'max_users' => $plan->max_users ?? ($meta['max_users'] ?? null),
            'duration_days' => $plan->duration_days ?? ($meta['duration_days'] ?? null),
            'duration_label' => $plan->duration_label ?? ($meta['duration_label'] ?? null),
            'email' => $order->email,
            'phone' => $order->phone,
            'amount_rial' => (int) $order->amount_rial,
            'amount_toman' => (int) floor(((int) $order->amount_rial) / 10),
            'status' => $order->status,
            'authority' => $order->authority,
            'ref_id' => $order->ref_id,
            'paid_at' => $order->paid_at,
            'created_at' => $order->created_at,
        ];
    }

    protected function sanitizeReturnUrl(?string $url): string
    {
        $fallback = rtrim((string) config('zarinpal.frontend_return_url'), '/').'/landing/products/class';
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

    protected function redirectUrl(?ProductPlanOrder $order, bool $ok, ?string $message = null): string
    {
        $base = $order && $order->return_url
            ? $order->return_url
            : rtrim((string) config('zarinpal.frontend_return_url'), '/').'/landing/products/class';
        $parts = parse_url($base) ?: [];
        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['payment'] = $ok ? 'ok' : 'failed';
        if ($order) {
            $query['oid'] = $order->id;
            if ($order->authority) {
                $query['authority'] = $order->authority;
            }
        }
        if ($message) {
            $query['message'] = $message;
        }
        $url = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'webinoo-plus.ir');
        if (! empty($parts['port'])) {
            $url .= ':'.$parts['port'];
        }
        $url .= $parts['path'] ?? '/landing/products/class';
        $url .= '?'.http_build_query($query);

        return $url;
    }
}
