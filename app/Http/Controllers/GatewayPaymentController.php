<?php

namespace App\Http\Controllers;

use App\Models\GatewayPayment;
use App\Models\User;
use App\Services\GatewayPaymentService;
use Illuminate\Http\Request;
use RuntimeException;

class GatewayPaymentController extends Controller
{
    public function catalog(Request $request, GatewayPaymentService $payments)
    {
        $this->shopAtelierIdOrAbort($request);

        return response($payments->catalog(), 200);
    }

    public function start(Request $request, GatewayPaymentService $payments)
    {
        $user = $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $fields = $request->validate([
            'type' => 'required|string|in:'.GatewayPayment::TYPE_SMS_PACKAGE.','.GatewayPayment::TYPE_SHOP_PLAN,
            'item_id' => 'required|integer|min:1',
            'id' => 'nullable|integer|min:1',
            'return_url' => 'nullable|string|max:1024',
        ]);

        $itemId = (int) ($fields['item_id'] ?? $fields['id'] ?? 0);

        try {
            $payload = $payments->start(
                $atelierId,
                $user instanceof User ? (int) $user->id : null,
                $fields['type'],
                $itemId,
                $fields['return_url'] ?? null,
                $user->phone ?? null
            );
        } catch (RuntimeException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'به درگاه زرین‌پال هدایت شوید.',
            'payment' => $payload,
            'payment_url' => $payload['payment_url'],
            'authority' => $payload['authority'],
        ], 201);
    }

    public function show(Request $request, string $authority, GatewayPaymentService $payments)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $row = $payments->statusForAtelier($atelierId, $authority);
        if (! $row) {
            return response(['message' => 'پرداخت یافت نشد.'], 404);
        }

        return response(['payment' => $row], 200);
    }

    public function zarinpalCallback(Request $request, GatewayPaymentService $payments)
    {
        $result = $payments->handleCallback(
            $request->query('Authority', $request->query('authority')),
            $request->query('Status', $request->query('status')),
            $request->query('pid') ? (int) $request->query('pid') : null
        );

        return redirect()->away($result['redirect']);
    }
}
