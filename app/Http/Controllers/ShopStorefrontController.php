<?php

namespace App\Http\Controllers;

use App\Models\Atelier;
use App\Models\Setting;
use Illuminate\Http\Request;

class ShopStorefrontController extends Controller
{
    /**
     * اطلاعات عمومی فروشگاه برای ویترین آنلاین (مسیر: api/{shop}).
     */
    public function show(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $atelier = Atelier::findOrFail($atelierId);

        Setting::setShopContext($atelierId);

        return response([
            'shop' => [
                'id' => $atelier->id,
                'name' => $atelier->name,
                'code' => $atelier->code,
                'address' => $atelier->address,
            ],
            'access' => $atelier->accessStatusForApi(),
            'settings' => [
                'enable_loyalty_credit' => Setting::isEnabled('enable_loyalty_credit', true),
            ],
        ]);
    }
}
