<?php

namespace App\Http\Concerns;

use App\Models\Atelier;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

trait ResolvesShopAtelierId
{
    /**
     * کاربر فعلی برای منطق فروشگاه.
     * مسیرهای api عمومی اغلب middleware auth:sanctum ندارند؛ در آن صورت $request->user()
     * گارد پیش‌فرض (web) را می‌زند و Bearer را نمی‌بیند — اینجا گارد sanctum هم امتحان می‌شود.
     */
    protected function shopRequestActor(Request $request): ?Authenticatable
    {
        $fromSanctum = $request->user('sanctum');
        if ($fromSanctum instanceof Authenticatable) {
            return $fromSanctum;
        }

        $fallback = $request->user();
        return $fallback instanceof Authenticatable ? $fallback : null;
    }

    /**
     * شناسهٔ فروشگاه برای فیلتر لیست‌ها: از کاربر لاگین، یا از کد فروشگاه در درخواست.
     */
    protected function shopAtelierIdOrAbort(Request $request): int
    {
        $actor = $this->shopRequestActor($request);
        if ($actor instanceof User && $actor->atelier_id) {
            return (int) $actor->atelier_id;
        }
        if ($actor instanceof Customer && $actor->atelier_id) {
            return (int) $actor->atelier_id;
        }

        $code = $request->header('X-Atelier-Code')
            ?: $request->query('atelier_code')
            ?: $request->input('atelier_code');
        if ($code) {
            $id = Atelier::where('code', $code)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        abort(response()->json([
            'message' => 'برای مشاهدهٔ لیست، کد فروشگاه (atelier_code در query/body یا هدر X-Atelier-Code) بفرستید، یا با توکن Sanctum کاربری که به فروشگاه (atelier_id) وصل است وارد شوید.',
        ], 422));
    }

    /**
     * شناسهٔ فروشگاه برای پرسنل (User) — فقط وقتی به فروشگاه وصل است.
     */
    protected function staffShopAtelierId(Request $request): ?int
    {
        $u = $this->shopRequestActor($request);
        if ($u instanceof User && $u->atelier_id) {
            return (int) $u->atelier_id;
        }

        return null;
    }

    /**
     * برای مدل Setting: زمینهٔ atelier از روی کاربر لاگین.
     */
    protected function bindShopSettingAtelierFromRequest(Request $request): void
    {
        Setting::setShopContext($this->staffShopAtelierId($request));
    }

    /**
     * اگر کاربر به فروشگاه وصل است، رکورد باید همان atelier_id را داشته باشد.
     */
    protected function assertModelBelongsToStaffAtelier(Request $request, ?Model $model): void
    {
        $aid = $this->staffShopAtelierId($request);
        if ($aid === null || ! $model) {
            return;
        }
        if (! isset($model->atelier_id) || (int) $model->atelier_id !== $aid) {
            abort(response()->json(['message' => 'یافت نشد'], 404));
        }
    }
}
