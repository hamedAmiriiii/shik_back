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
    public function __construct(
        protected ZarinpalClient $zarinpal,
        protected SepClient $sep,
    ) {
    }

    /**
     * @return array{sms_packages: array<int, mixed>, shop_plans: array<int, mixed>, gateways: array<int, array<string, string>>, currency: string, shop_subscription?: array<string, mixed>}
     */
    public function catalog(?int $atelierId = null): array
    {
        $sms = [];
        if (Schema::hasTable('sms_packages')) {
            $sms = SmsPackage::query()->active()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (SmsPackage $p) => $this->formatSmsPackage($p))
                ->all();
        }

        $plans = [];
        if (Schema::hasTable('shop_plans')) {
            $projectType = null;
            if ($atelierId) {
                $atelierForType = Atelier::query()->find($atelierId);
                if ($atelierForType) {
                    $projectType = $atelierForType->projectType();
                }
            }
            $plans = ShopPlan::query()
                ->active()
                ->when($projectType !== null, fn ($q) => $q->forProject($projectType))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (ShopPlan $p) => $this->formatShopPlan($p))
                ->all();
        }

        $payload = [
            'sms_packages' => $sms,
            'shop_plans' => $plans,
            'currency' => 'IRR',
            'gateway' => GatewayPayment::GATEWAY_ZARINPAL,
            'gateways' => [
                ['id' => GatewayPayment::GATEWAY_ZARINPAL, 'name' => 'زرین‌پال'],
                ['id' => GatewayPayment::GATEWAY_SEP, 'name' => 'سامان کیش (SEP)'],
            ],
            'default_gateway' => GatewayPayment::GATEWAY_ZARINPAL,
        ];

        if ($atelierId) {
            $atelier = Atelier::query()->find($atelierId);
            if ($atelier) {
                $payload['shop_subscription'] = [
                    'current_price_rial' => $atelier->subscription_current_price_rial !== null
                        ? (int) $atelier->subscription_current_price_rial
                        : null,
                    'current_price_toman' => $atelier->subscription_current_price_rial !== null
                        ? (int) floor(((int) $atelier->subscription_current_price_rial) / 10)
                        : null,
                    'renewal_price_rial' => $atelier->effectiveRenewalPriceRial(),
                    'renewal_price_toman' => $atelier->effectiveRenewalPriceRial() !== null
                        ? (int) floor($atelier->effectiveRenewalPriceRial() / 10)
                        : null,
                    'renewal_days' => $atelier->effectiveRenewalDays(),
                    'has_custom_renewal' => $atelier->hasCustomRenewalPrice(),
                ];

                if ($atelier->hasCustomRenewalPrice()) {
                    $payload['shop_plans'] = [$this->formatShopCustomRenewalPlan($atelier)];
                }
            }
        }

        return $payload;
    }

    /**
     * پلن تمدید اختصاصی فروشگاه — همان مبلغ تمدید ثبت‌شده برای این فروشگاه.
     *
     * @return array<string, mixed>
     */
    public function formatShopCustomRenewalPlan(Atelier $atelier): array
    {
        $days = $atelier->effectiveRenewalDays();
        $priceRial = (int) $atelier->effectiveRenewalPriceRial();
        $planQuery = ShopPlan::query()->active()->forProject($atelier->projectType());
        $plan = (clone $planQuery)->where('duration_days', $days)->orderBy('sort_order')->orderBy('id')->first()
            ?? $planQuery->orderBy('sort_order')->orderBy('id')->first();

        return [
            'id' => $plan ? (int) $plan->id : 0,
            'type' => GatewayPayment::TYPE_SHOP_PLAN,
            'name' => 'تمدید اشتراک',
            'duration_days' => $days,
            'price_rial' => $priceRial,
            'price_toman' => (int) floor($priceRial / 10),
            'discount_price_rial' => null,
            'discount_price_toman' => null,
            'payable_price_rial' => $priceRial,
            'payable_price_toman' => (int) floor($priceRial / 10),
            'sort_order' => 0,
            'is_shop_custom_price' => true,
            'project_type' => $atelier->projectType(),
            'description' => 'مبلغ تمدید اختصاصی فروشگاه شما',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function start(
        int $atelierId,
        ?int $userId,
        string $type,
        int $itemId,
        ?string $returnUrl,
        ?string $mobile = null,
        string $gateway = GatewayPayment::GATEWAY_ZARINPAL,
    ): array {
        if (! Schema::hasTable('gateway_payments')) {
            throw new RuntimeException('جدول پرداخت ساخته نشده است. SQL درگاه را اجرا کنید.');
        }

        $gateway = $this->normalizeGateway($gateway);

        [$amount, $description, $meta] = $this->resolveItem($type, $itemId, $atelierId);
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
            'gateway' => $gateway,
            'return_url' => $this->sanitizeReturnUrl($returnUrl),
            'meta' => $meta,
        ]);

        if ($gateway === GatewayPayment::GATEWAY_SEP) {
            return $this->startSep($payment, $mobile);
        }

        return $this->startZarinpal($payment, $mobile);
    }

    /**
     * @return array<string, mixed>
     */
    protected function startZarinpal(GatewayPayment $payment, ?string $mobile): array
    {
        $callback = rtrim((string) (config('zarinpal.callback_url') ?: url('/api/payments/zarinpal/callback')), '/');
        $metadata = [];
        if (is_string($mobile) && $mobile !== '') {
            $metadata['mobile'] = $mobile;
        }

        $authority = $this->zarinpal->request(
            (int) $payment->amount_rial,
            $callback.'?pid='.$payment->id,
            (string) $payment->description,
            $metadata
        );

        $payment->update(['authority' => $authority]);

        return $this->formatPayment($payment->fresh(), $this->zarinpal->startPayUrl($authority));
    }

    /**
     * @return array<string, mixed>
     */
    protected function startSep(GatewayPayment $payment, ?string $mobile): array
    {
        $callback = $this->sepRedirectUrl();
        $resNum = (string) $payment->id;
        $tokenResult = $this->sep->requestToken(
            (int) $payment->amount_rial,
            $resNum,
            $callback,
            $mobile
        );

        $meta = $payment->meta ?? [];
        $meta['sep'] = [
            'res_num' => $resNum,
            'token' => $tokenResult['token'],
            'redirect_url' => $callback,
        ];
        $payment->update([
            'authority' => $tokenResult['token'],
            'meta' => $meta,
        ]);

        // صفحه میانی که فرم POST به درگاه سامان می‌فرستد (Referrer الزامی است)
        $goUrl = url('/api/payments/sep/go?pid='.$payment->id);

        return $this->formatPayment($payment->fresh(), $goUrl, [
            'redirect_method' => 'GET',
            'sep_pay_url' => $this->sep->payUrl(),
            'sep_token' => $tokenResult['token'],
        ]);
    }

    /**
     * RedirectUrl ثبت‌شده در پنل SEP — باید دقیقاً همان باشد.
     */
    protected function sepRedirectUrl(): string
    {
        $fallback = 'https://webinoo-plus.ir/pay';
        $callback = trim((string) config('sep.callback_url', $fallback));
        if ($callback === '') {
            return $fallback;
        }

        // اگر اشتباهاً آدرس API گذاشته شده، به دامنهٔ پذیرنده اصلاح کن
        $host = parse_url($callback, PHP_URL_HOST) ?: '';
        if ($host === 'api.webinoo-plus.ir' || $host === 'api.webinooplus.ir'
            || stripos($callback, '/api/payments/sep') !== false) {
            return $fallback;
        }

        return rtrim($callback, '/');
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
            $this->fulfill($payment, $verified['ref_id'] ?? null, $verified, 'zarinpal');
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
     * Callback درگاه سامان — معمولاً POST با State / RefNum / ResNum.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, payment: array<string, mixed>, redirect: string}
     */
    public function handleSepCallback(array $payload, ?int $paymentId = null): array
    {
        $state = strtoupper((string) ($payload['State'] ?? $payload['state'] ?? ''));
        $refNum = (string) ($payload['RefNum'] ?? $payload['refNum'] ?? '');
        $resNum = (string) ($payload['ResNum'] ?? $payload['resNum'] ?? '');
        $stateCode = (string) ($payload['StateCode'] ?? $payload['stateCode'] ?? '');

        $payment = null;
        if ($paymentId) {
            $payment = GatewayPayment::query()->find($paymentId);
        }
        if (! $payment && $resNum !== '') {
            $id = (int) preg_replace('/\D+/', '', $resNum);
            if ($id > 0) {
                $payment = GatewayPayment::query()->find($id);
            }
        }
        if (! $payment && $resNum !== '' && strpos($resNum, 'gp') === 0) {
            $payment = GatewayPayment::query()->find((int) substr($resNum, 2));
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

        $okState = $state === 'OK' || $stateCode === '0' || $stateCode === '00';
        if (! $okState || $refNum === '') {
            $payment->update(['status' => GatewayPayment::STATUS_CANCELED]);

            return [
                'ok' => false,
                'payment' => $this->formatPayment($payment),
                'redirect' => $this->redirectUrl($payment, false, 'پرداخت لغو شد یا ناموفق بود.'),
            ];
        }

        try {
            $verified = $this->sep->verify($refNum);
            if ($verified['amount'] !== null && (int) $verified['amount'] !== (int) $payment->amount_rial) {
                throw new RuntimeException('مبلغ پرداخت با فاکتور هم‌خوانی ندارد.');
            }
            $metaExtra = [
                'ref_num' => $refNum,
                'res_num' => $resNum,
                'state' => $state,
                'trace_no' => $verified['trace_no'],
                'card_pan' => $verified['card_pan'],
            ];
            $this->fulfill($payment, $verified['ref_id'] ?? $refNum, $metaExtra, 'sep');
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

    public function sepGoHtml(int $paymentId): string
    {
        $payment = GatewayPayment::query()->find($paymentId);
        if (! $payment || $payment->gateway !== GatewayPayment::GATEWAY_SEP) {
            throw new RuntimeException('پرداخت سامان یافت نشد.');
        }
        $token = (string) ($payment->meta['sep']['token'] ?? $payment->authority ?? '');
        if ($token === '') {
            throw new RuntimeException('توکن پرداخت منقضی یا نامعتبر است.');
        }
        $action = htmlspecialchars($this->sep->payUrl(), ENT_QUOTES, 'UTF-8');
        $tokenEsc = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"/><title>انتقال به درگاه سامان</title></head><body>'
            .'<p style="font-family:Tahoma;text-align:center;margin-top:40px">در حال انتقال به درگاه سامان...</p>'
            .'<form id="sepPay" method="post" action="'.$action.'">'
            .'<input type="hidden" name="Token" value="'.$tokenEsc.'"/>'
            .'<input type="hidden" name="GetMethod" value=""/>'
            .'</form><script>document.getElementById("sepPay").submit();</script>'
            .'</body></html>';
    }

    protected function normalizeGateway(string $gateway): string
    {
        $gateway = strtolower(trim($gateway));
        if (! in_array($gateway, GatewayPayment::gateways(), true)) {
            throw new RuntimeException('درگاه پرداخت نامعتبر است.');
        }

        return $gateway;
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
    public function fulfill(GatewayPayment $payment, ?string $refId, array $verified = [], string $gatewayKey = 'zarinpal'): GatewayPayment
    {
        return DB::transaction(function () use ($payment, $refId, $verified, $gatewayKey) {
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
            $meta[$gatewayKey] = array_merge($meta[$gatewayKey] ?? [], [
                'ref_id' => $refId,
                'card_pan' => $verified['card_pan'] ?? null,
                'ref_num' => $verified['ref_num'] ?? null,
                'trace_no' => $verified['trace_no'] ?? null,
            ]);
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
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function formatPayment(GatewayPayment $payment, ?string $paymentUrl = null, array $extra = []): array
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

        return array_merge($row, $extra);
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
        $priceRial = (int) $plan->price_rial;
        $discountRial = Schema::hasColumn('shop_plans', 'discount_price_rial')
            ? ($plan->discount_price_rial !== null ? (int) $plan->discount_price_rial : null)
            : null;
        $payableRial = $plan->payablePriceRial();
        $payload = [
            'id' => $plan->id,
            'type' => GatewayPayment::TYPE_SHOP_PLAN,
            'name' => $plan->name,
            'duration_days' => $plan->duration_days,
            'price_rial' => $priceRial,
            'price_toman' => (int) floor($priceRial / 10),
            'discount_price_rial' => $discountRial,
            'discount_price_toman' => $discountRial !== null ? (int) floor($discountRial / 10) : null,
            'payable_price_rial' => $payableRial,
            'payable_price_toman' => (int) floor($payableRial / 10),
            'sort_order' => $plan->sort_order,
            'is_active' => (bool) $plan->is_active,
        ];
        if (Schema::hasColumn('shop_plans', 'project_type')) {
            $payload['project_type'] = $plan->projectType();
        }
        if (Schema::hasColumn('shop_plans', 'description')) {
            $payload['description'] = $plan->description;
        }

        return $payload;
    }

    /**
     * @return array{0: int, 1: string, 2: array<string, mixed>}
     */
    protected function resolveItem(string $type, int $itemId, ?int $atelierId = null): array
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
            $atelier = $atelierId ? Atelier::query()->find($atelierId) : null;
            if ($atelier && $atelier->hasCustomRenewalPrice()) {
                $days = $atelier->effectiveRenewalDays();
                $amount = (int) $atelier->effectiveRenewalPriceRial();
                $plan = $itemId > 0 ? ShopPlan::query()->find($itemId) : null;

                return [
                    $amount,
                    'تمدید اشتراک '.$atelier->name,
                    [
                        'duration_days' => $days,
                        'name' => 'تمدید اشتراک',
                        'shop_custom_price' => true,
                        'plan_id' => $plan?->id,
                    ],
                ];
            }

            $plan = ShopPlan::query()->active()->find($itemId);
            if (! $plan) {
                throw new RuntimeException('پلن اکانت یافت نشد.');
            }
            if ($atelier && Schema::hasColumn('shop_plans', 'project_type')
                && $plan->projectType() !== $atelier->projectType()) {
                throw new RuntimeException('این پلن برای نوع کسب‌وکار شما نیست.');
            }

            return [
                (int) $plan->payablePriceRial(),
                'خرید اکانت '.$plan->name,
                [
                    'duration_days' => $plan->duration_days,
                    'name' => $plan->name,
                    'list_price_rial' => (int) $plan->price_rial,
                    'discount_price_rial' => Schema::hasColumn('shop_plans', 'discount_price_rial')
                        ? ($plan->discount_price_rial !== null ? (int) $plan->discount_price_rial : null)
                        : null,
                ],
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
            'admin_note' => 'پرداخت آنلاین ('.$payment->gateway.')'.($payment->authority ? ' / '.$payment->authority : ''),
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
        // مبلغ پرداخت‌شده به‌عنوان قیمت فعلی ذخیره می‌شود؛ قیمت تمدید بعدی دست‌نخورده می‌ماند.
        $atelier->subscription_current_price_rial = (int) $payment->amount_rial;
        if (! $atelier->hasCustomRenewalPrice()) {
            $atelier->subscription_renewal_price_rial = (int) $payment->amount_rial;
            $atelier->subscription_renewal_days = $days;
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
