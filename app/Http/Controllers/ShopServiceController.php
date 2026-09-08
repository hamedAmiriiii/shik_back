<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\ShopService;
use Illuminate\Http\Request;

class ShopServiceController extends Controller
{
    /**
     * کاتالوگ خدمات فعال برای مهمان میز/اتاق
     * GET /api/{shop}/shop-services
     */
    public function publicIndex(Request $request, $shop = null)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        Setting::setShopContext($atelierId);
        $enabled = Setting::isEnabled('room_services_enabled', false);

        $services = [];
        if ($enabled) {
            $services = ShopService::where('atelier_id', $atelierId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (ShopService $service) => $service->toPublicArray())
                ->values()
                ->all();
        }

        return response()->json([
            'enabled' => $enabled,
            'services' => $services,
        ]);
    }

    public function index(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);
        Setting::setShopContext($atelierId);
        $enabled = Setting::isEnabled('room_services_enabled', false);

        $services = [];
        if ($enabled) {
            $services = ShopService::where('atelier_id', $atelierId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (ShopService $service) => $service->toPublicArray())
                ->values()
                ->all();
        }

        return response()->json([
            'enabled' => $enabled,
            'services' => $services,
        ]);
    }

    public function store(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $this->assertRoomServicesEnabled($atelierId);

        $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'icon_key' => 'nullable|string|in:'.implode(',', ShopService::ICONS),
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_active' => 'nullable|boolean',
        ]);

        $maxSort = (int) ShopService::where('atelier_id', $atelierId)->max('sort_order');

        $service = ShopService::create([
            'atelier_id' => $atelierId,
            'name' => trim($request->name),
            'description' => $request->input('description'),
            'icon_key' => $request->input('icon_key', 'other') ?: 'other',
            'sort_order' => $request->filled('sort_order') ? (int) $request->sort_order : $maxSort + 1,
            'is_active' => $request->input('is_active', true),
        ]);

        return response()->json($service->toPublicArray(), 201);
    }

    public function update(Request $request, ShopService $shopService)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $shopService);
        $this->assertRoomServicesEnabled((int) $shopService->atelier_id);

        $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'description' => 'nullable|string|max:500',
            'icon_key' => 'nullable|string|in:'.implode(',', ShopService::ICONS),
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_active' => 'nullable|boolean',
        ]);

        $shopService->update($request->only(['name', 'description', 'icon_key', 'sort_order', 'is_active']));

        return response()->json($shopService->fresh()->toPublicArray());
    }

    public function destroy(Request $request, ShopService $shopService)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $shopService);
        $this->assertRoomServicesEnabled((int) $shopService->atelier_id);
        $shopService->delete();

        return response()->json(['message' => 'خدمت حذف شد']);
    }

    private function assertRoomServicesEnabled(int $atelierId): void
    {
        Setting::setShopContext($atelierId);
        if (! Setting::isEnabled('room_services_enabled', false)) {
            abort(response()->json(['message' => 'خدمات اتاق برای این فروشگاه فعال نیست.'], 403));
        }
    }
}
