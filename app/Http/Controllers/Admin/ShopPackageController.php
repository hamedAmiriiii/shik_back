<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Services\ShopPackageCatalog;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ShopPackageController extends Controller
{
    public function index(Request $request)
    {
        $this->requirePlatformAdmin($request);

        $rows = ShopPackageCatalog::adminList();

        return response(['data' => $rows, 'shop_packages' => $rows], 200);
    }

    public function update(Request $request, string $slug)
    {
        $this->requirePlatformAdmin($request);

        $slug = trim($slug);
        $pkg = ShopPackageCatalog::findBySlug($slug);
        if (! $pkg) {
            return response(['message' => 'پکیج یافت نشد.'], 404);
        }

        $fields = $request->validate([
            'price_toman' => 'required|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
        ]);

        $toman = (int) round((float) ($fields['price_toman'] ?? $fields['price'] ?? 0));
        // اگر اشتباهاً ریال فرستاده شده باشد (خیلی بزرگ)، به تومان تبدیل نکن — ادمین تومان می‌زند

        try {
            ShopPackageCatalog::setPriceToman($slug, $toman);
        } catch (InvalidArgumentException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        $updated = ShopPackageCatalog::findBySlug($slug);

        return response([
            'message' => 'قیمت پکیج ذخیره شد.',
            'data' => [
                'id' => $updated['id'] ?? $pkg['id'],
                'slug' => $slug,
                'name' => $updated['name'] ?? $pkg['name'],
                'price_toman' => (int) ($updated['price_toman'] ?? $toman),
                'price_rial' => (int) ($updated['price_rial'] ?? $toman * 10),
                'duration_days' => (int) ($updated['duration_days'] ?? $pkg['duration_days']),
                'price_overridden' => true,
            ],
        ], 200);
    }

    protected function requirePlatformAdmin(Request $request): User
    {
        $actor = $this->shopRequestActor($request);
        if ($actor instanceof Customer) {
            abort(response()->json(['message' => 'دسترسی مجاز نیست.'], 403));
        }
        if (! $actor instanceof User) {
            abort(response()->json(['message' => 'لطفاً وارد شوید.'], 401));
        }
        if (! $actor->roles()->where('id', User::USER_TYPE_KEY['ادمین'])->exists()) {
            abort(response()->json(['message' => 'فقط ادمین می‌تواند قیمت پکیج منو را مدیریت کند.'], 403));
        }

        return $actor;
    }
}
