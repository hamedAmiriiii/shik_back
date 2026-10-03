<?php

namespace App\Http\Controllers;

use App\Services\GoogleSheets\GoogleSheetsException;
use App\Services\GoogleSheets\ShopGoogleOAuth;
use App\Services\GoogleSheets\ShopGoogleSheetExportService;
use Illuminate\Http\Request;

class ShopGoogleSheetController extends Controller
{
    /**
     * وضعیت اتصال گوگل شیت همین فروشگاه.
     * GET /api/shop-backup/google-sheet
     */
    public function show(Request $request, ShopGoogleSheetExportService $sheets)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        return response($sheets->status($atelierId), 200);
    }

    /**
     * ثبت لینک شیت (دسترسی Service Account همین‌جا بررسی می‌شود).
     * PUT /api/shop-backup/google-sheet  {spreadsheet: "لینک یا شناسه"}
     */
    public function update(Request $request, ShopGoogleSheetExportService $sheets)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $request->validate([
            'spreadsheet' => 'required|string|max:500',
        ]);

        try {
            $status = $sheets->connect($atelierId, (string) $request->input('spreadsheet'));
        } catch (GoogleSheetsException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        return response(array_merge(['message' => 'گوگل شیت متصل شد.'], $status), 200);
    }

    /**
     * DELETE /api/shop-backup/google-sheet
     */
    public function destroy(Request $request, ShopGoogleSheetExportService $sheets)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $sheets->disconnect($atelierId);

        return response(array_merge(['message' => 'اتصال گوگل شیت حذف شد.'], $sheets->status($atelierId)), 200);
    }

    /**
     * جداولی که به گوگل شیت فرستاده می‌شوند.
     * PUT /api/shop-backup/google-sheet/tables  {tables: ["purchases", ...]}
     */
    public function updateTables(Request $request, ShopGoogleSheetExportService $sheets)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $request->validate([
            'tables' => 'required|array|min:1',
            'tables.*' => 'string|max:100',
        ]);

        try {
            $sheets->setSelectedTables($atelierId, (array) $request->input('tables'));
        } catch (GoogleSheetsException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        return response(array_merge(['message' => 'جداول ارسالی ذخیره شد.'], $sheets->status($atelierId)), 200);
    }

    /**
     * آدرس صفحهٔ ورود گوگل برای اتصال حساب خود فروشگاه.
     * POST /api/shop-backup/google-sheet/oauth-url  {return_url}
     */
    public function oauthUrl(Request $request, ShopGoogleOAuth $oauth)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $request->validate([
            'return_url' => 'nullable|string|max:1024',
        ]);

        try {
            $url = $oauth->authorizationUrl($atelierId, $this->safeReturnUrl($request->input('return_url')));
        } catch (GoogleSheetsException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        return response(['url' => $url], 200);
    }

    /**
     * برگشت از گوگل (بدون توکن کاربر؛ فروشگاه از state رمزشده خوانده می‌شود).
     * GET /api/google-sheet/oauth/callback
     */
    public function oauthCallback(Request $request, ShopGoogleOAuth $oauth)
    {
        $returnUrl = $this->safeReturnUrl(null);

        try {
            $state = $oauth->decodeState((string) $request->query('state', ''));
            $returnUrl = $this->safeReturnUrl($state['return_url']);

            if ($request->filled('error')) {
                return redirect()->away($this->withQuery($returnUrl, [
                    'google_sheet' => 'error',
                    'message' => 'اتصال حساب گوگل لغو شد.',
                ]));
            }

            $code = (string) $request->query('code', '');
            if ($code === '') {
                throw new GoogleSheetsException('کد تأیید گوگل دریافت نشد.');
            }

            $email = $oauth->completeAuthorization($state['atelier_id'], $code);
        } catch (GoogleSheetsException $e) {
            return redirect()->away($this->withQuery($returnUrl, [
                'google_sheet' => 'error',
                'message' => $e->getMessage(),
            ]));
        }

        return redirect()->away($this->withQuery($returnUrl, [
            'google_sheet' => 'connected',
            'email' => $email,
        ]));
    }

    private function safeReturnUrl($url): string
    {
        $fallback = rtrim((string) config('zarinpal.frontend_return_url'), '/').'/admin/settings';
        if (! is_string($url) || trim($url) === '') {
            return $fallback;
        }
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $scheme = $parts['scheme'] ?? null;
        $allowed = (array) config('zarinpal.allowed_return_hosts', []);
        $fallbackHost = parse_url($fallback, PHP_URL_HOST);
        if ($fallbackHost) {
            $allowed[] = $fallbackHost;
        }
        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || ! in_array($host, $allowed, true)) {
            return $fallback;
        }

        return $url;
    }

    /**
     * @param  array<string, string>  $params
     */
    private function withQuery(string $url, array $params): string
    {
        $parts = parse_url($url) ?: [];
        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        unset($query['google_sheet'], $query['message'], $query['email']);
        $query = array_merge($query, $params);

        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '/');

        return $base.'?'.http_build_query($query);
    }

    /**
     * ارسال دستی همهٔ داده‌های فروشگاه به شیت.
     * POST /api/shop-backup/google-sheet/export
     */
    public function export(Request $request, ShopGoogleSheetExportService $sheets)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);
        @set_time_limit(600);

        try {
            $result = $sheets->export($atelierId);
        } catch (GoogleSheetsException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        return response(array_merge(['message' => 'داده‌ها به گوگل شیت ارسال شد.'], $result), 200);
    }
}
