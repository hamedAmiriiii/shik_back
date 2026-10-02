<?php

namespace App\Http\Controllers;

use App\Services\GoogleSheets\GoogleSheetsException;
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
