<?php

namespace App\Http\Controllers;

use App\Models\GatewayPayment;
use App\Models\User;
use App\Services\GatewayPaymentService;
use App\Services\ShopPackageCatalog;
use Illuminate\Http\Request;
use RuntimeException;

class ShopPackageController extends Controller
{
    public function index()
    {
        return response([
            'currency' => 'IRR',
            'data' => ShopPackageCatalog::publicList(),
        ], 200);
    }

    public function start(Request $request, GatewayPaymentService $payments)
    {
        $user = $this->requireStaffShopUser($request);
        $atelierId = $this->resolveShopAtelierIdOrAbort($request);

        $fields = $request->validate([
            'package' => 'nullable|string|max:64',
            'slug' => 'nullable|string|max:64',
            'item_id' => 'nullable|integer|min:1',
            'id' => 'nullable|integer|min:1',
            'return_url' => 'nullable|string|max:1024',
            'gateway' => 'nullable|string|in:'.implode(',', GatewayPayment::gateways()),
        ]);

        $pkg = null;
        $slug = trim((string) ($fields['package'] ?? $fields['slug'] ?? ''));
        if ($slug !== '') {
            $pkg = ShopPackageCatalog::findBySlug($slug);
        }
        if (! $pkg) {
            $itemId = (int) ($fields['item_id'] ?? $fields['id'] ?? 0);
            if ($itemId > 0) {
                $pkg = ShopPackageCatalog::findById($itemId);
            }
        }
        if (! $pkg) {
            return response(['message' => 'پکیج انتخاب‌شده معتبر نیست.'], 422);
        }

        $gateway = $fields['gateway'] ?? GatewayPayment::GATEWAY_ZARINPAL;

        try {
            $payload = $payments->start(
                $atelierId,
                $user instanceof User ? (int) $user->id : null,
                GatewayPayment::TYPE_SHOP_PACKAGE,
                (int) $pkg['id'],
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
            'package' => [
                'id' => $pkg['id'],
                'slug' => $pkg['slug'],
                'name' => $pkg['name'],
            ],
            'payment' => $payload,
            'payment_url' => $payload['payment_url'],
            'authority' => $payload['authority'],
            'gateway' => $payload['gateway'] ?? $gateway,
        ], 201);
    }
}
