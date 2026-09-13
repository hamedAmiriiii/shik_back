<?php

namespace App\Http\Controllers;

use App\Models\GatewayPayment;
use App\Models\ProductPlanOrder;
use App\Models\User;
use App\Services\GatewayPaymentService;
use App\Services\ProductPlanOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class GatewayPaymentController extends Controller
{
    public function catalog(Request $request, GatewayPaymentService $payments)
    {
        // تمدید بعد از انقضا باید ممکن باشد — فقط شناسه فروشگاه لازم است.
        $atelierId = $this->resolveShopAtelierIdOrAbort($request);

        return response($payments->catalog($atelierId), 200);
    }

    public function start(Request $request, GatewayPaymentService $payments)
    {
        $user = $this->requireStaffShopUser($request);
        $atelierId = $this->resolveShopAtelierIdOrAbort($request);

        $fields = $request->validate([
            'type' => 'required|string|in:'.GatewayPayment::TYPE_SMS_PACKAGE.','.GatewayPayment::TYPE_SHOP_PLAN,
            'item_id' => 'required|integer|min:0',
            'id' => 'nullable|integer|min:0',
            'return_url' => 'nullable|string|max:1024',
            'gateway' => 'nullable|string|in:'.implode(',', GatewayPayment::gateways()),
        ]);

        $itemId = (int) ($fields['item_id'] ?? $fields['id'] ?? 0);
        $gateway = $fields['gateway'] ?? GatewayPayment::GATEWAY_ZARINPAL;

        // خرید پیامک فقط وقتی دسترسی فعال است؛ تمدید اشتراک حتی بعد از انقضا مجاز است.
        if ($fields['type'] === GatewayPayment::TYPE_SMS_PACKAGE
            && ! $this->shopRequestActorIsPlatformAdmin($request)) {
            $this->assertShopAccessActive($atelierId);
        }

        try {
            $payload = $payments->start(
                $atelierId,
                $user instanceof User ? (int) $user->id : null,
                $fields['type'],
                $itemId,
                $fields['return_url'] ?? null,
                $user->phone ?? null,
                $gateway
            );
        } catch (RuntimeException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        $gatewayLabel = $gateway === GatewayPayment::GATEWAY_SEP ? 'سامان کیش' : 'زرین‌پال';

        return response([
            'message' => 'به درگاه '.$gatewayLabel.' هدایت شوید.',
            'payment' => $payload,
            'payment_url' => $payload['payment_url'],
            'authority' => $payload['authority'],
            'gateway' => $payload['gateway'] ?? $gateway,
        ], 201);
    }

    public function show(Request $request, string $authority, GatewayPaymentService $payments)
    {
        $atelierId = $this->resolveShopAtelierIdOrAbort($request);
        $row = $payments->statusForAtelier($atelierId, $authority);
        if (! $row) {
            return response(['message' => 'پرداخت یافت نشد.'], 404);
        }

        return response(['payment' => $row], 200);
    }

    public function zarinpalCallback(Request $request, GatewayPaymentService $payments, ProductPlanOrderService $planOrders)
    {
        $orderId = $request->query('oid') ? (int) $request->query('oid') : null;
        if ($orderId || $this->looksLikeProductPlanOrder($request->query('Authority', $request->query('authority')))) {
            $result = $planOrders->handleCallback(
                $request->query('Authority', $request->query('authority')),
                $request->query('Status', $request->query('status')),
                $orderId
            );

            return redirect()->away($result['redirect']);
        }

        $result = $payments->handleCallback(
            $request->query('Authority', $request->query('authority')),
            $request->query('Status', $request->query('status')),
            $request->query('pid') ? (int) $request->query('pid') : null
        );

        return redirect()->away($result['redirect']);
    }

    public function sepGo(Request $request, GatewayPaymentService $payments)
    {
        $pid = (int) $request->query('pid');
        if ($pid <= 0) {
            return response('شناسه پرداخت نامعتبر است.', 400);
        }

        try {
            return response($payments->sepGoHtml($pid), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        } catch (RuntimeException $e) {
            return response($e->getMessage(), 422);
        }
    }

    public function sepCallback(Request $request, GatewayPaymentService $payments)
    {
        $payload = array_merge($request->query(), $request->request->all());
        $pid = isset($payload['pid']) ? (int) $payload['pid'] : null;
        $result = $payments->handleSepCallback($payload, $pid ?: null);

        return redirect()->away($result['redirect']);
    }

    protected function looksLikeProductPlanOrder($authority): bool
    {
        if (! is_string($authority) || $authority === '') {
            return false;
        }

        return Schema::hasTable('product_plan_orders')
            && ProductPlanOrder::query()->where('authority', $authority)->exists();
    }
}
