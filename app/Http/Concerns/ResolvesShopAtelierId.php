<?php

namespace App\Http\Concerns;

use App\Models\Atelier;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

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

        $plainToken = $this->resolveBearerTokenFromRequest($request);
        if ($plainToken) {
            $accessToken = PersonalAccessToken::findToken($plainToken);
            if ($accessToken && $accessToken->tokenable instanceof Authenticatable) {
                return $accessToken->tokenable;
            }
        }

        $fallback = $request->user();
        return $fallback instanceof Authenticatable ? $fallback : null;
    }

    /**
     * توکن Sanctum از هدر Authorization، هدرهای جایگزین، یا فیلد body (token).
     */
    protected function resolveBearerTokenFromRequest(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return trim($bearer);
        }

        $authorization = $request->header('Authorization');
        if (is_string($authorization) && $authorization !== '') {
            if (stripos($authorization, 'Bearer ') === 0) {
                return trim(substr($authorization, 7));
            }

            return trim($authorization);
        }

        foreach (['X-Auth-Token', 'X-Api-Token'] as $header) {
            $value = $request->header($header);
            if (is_string($value) && $value !== '') {
                return trim($value);
            }
        }

        $bodyToken = $request->input('token');
        if (is_string($bodyToken) && $bodyToken !== '') {
            return trim($bodyToken);
        }

        foreach (['access_token', 'auth_token', 'bearer_token'] as $key) {
            $t = $request->input($key);
            if (is_string($t) && $t !== '') {
                return trim($t);
            }
        }

        return null;
    }

    /**
     * atelier_id عددی از body / query / هدر (فرانت گاهی atelier_id می‌فرستد نه atelier_code).
     */
    protected function parseRequestedAtelierId(Request $request): ?int
    {
        $raw = $request->header('X-Atelier-Id')
            ?: $request->query('atelier_id')
            ?: $request->input('atelier_id');

        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    /**
     * شناسهٔ فروشگاه برای فیلتر لیست‌ها: از کاربر لاگین، یا از کد فروشگاه در درخواست.
     */
    protected function shopAtelierIdOrAbort(Request $request): int
    {
        $actor = $this->shopRequestActor($request);
        $requestedAtelierId = $this->parseRequestedAtelierId($request);

        if ($actor instanceof User) {
            $freshAtelierId = User::where('id', $actor->id)->value('atelier_id');
            if ($freshAtelierId) {
                return (int) $freshAtelierId;
            }

            if ($requestedAtelierId !== null && Atelier::where('id', $requestedAtelierId)->exists()) {
                return $requestedAtelierId;
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
                'message' => 'حساب شما به فروشگاه متصل نیست. پس از ثبت فروشگاه دوباره وارد شوید، یا atelier_id / atelier_code معتبر بفرستید.',
            ], 422));
        }
        if ($actor instanceof Customer && $actor->atelier_id) {
            return (int) $actor->atelier_id;
        }

        $plainToken = $this->resolveBearerTokenFromRequest($request);
        if ($plainToken && ! $actor) {
            abort(response()->json([
                'message' => 'توکن نامعتبر یا منقضی شده است.',
            ], 401));
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
            'message' => 'توکن معتبر (Authorization: Bearer ...)، یا atelier_id / atelier_code در body بفرستید.',
        ], 422));
    }

    /**
     * شناسهٔ فروشگاه برای پرسنل (User) — فقط وقتی به فروشگاه وصل است.
     */
    protected function staffShopAtelierId(Request $request): ?int
    {
        $u = $this->shopRequestActor($request);
        if ($u instanceof User) {
            $freshAtelierId = User::where('id', $u->id)->value('atelier_id');
            if ($freshAtelierId) {
                return (int) $freshAtelierId;
            }
        }

        return null;
    }

    /**
     * پرسنل فروشگاه (User با Sanctum) — برای endpointهای ادمین فروشگاه.
     */
    protected function requireStaffShopUser(Request $request): User
    {
        $actor = $this->shopRequestActor($request);
        if ($actor instanceof Customer) {
            abort(response()->json([
                'message' => 'این عملیات فقط برای پرسنل فروشگاه است.',
            ], 403));
        }
        if (! $actor instanceof User) {
            abort(response()->json([
                'message' => 'لطفاً با توکن Sanctum وارد شوید.',
            ], 401));
        }

        return $actor;
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
