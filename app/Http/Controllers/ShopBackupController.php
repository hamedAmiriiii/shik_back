<?php

namespace App\Http\Controllers;

use App\Services\ShopBackupService;
use Illuminate\Http\Request;
use RuntimeException;

class ShopBackupController extends Controller
{
    /**
     * خلاصهٔ داده‌هایی که در پشتیبان می‌آید.
     * GET /api/shop-backup
     */
    public function show(Request $request, ShopBackupService $backups)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        return response($backups->summary($atelierId), 200);
    }

    /**
     * دانلود فایل zip پشتیبان همین فروشگاه.
     * GET /api/shop-backup/download
     */
    public function download(Request $request, ShopBackupService $backups)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);
        @set_time_limit(300);

        try {
            $zip = $backups->createZip($atelierId);
        } catch (RuntimeException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        return response()->download($zip['path'], $zip['filename'], [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * بازگردانی جایگزین روی همین فروشگاه.
     * POST /api/shop-backup/restore
     * multipart: file (zip) + confirm=بازگردانی
     */
    public function restore(Request $request, ShopBackupService $backups)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);
        @set_time_limit(300);

        $request->validate([
            'file' => 'required|file|max:204800',
            'confirm' => 'required',
        ]);

        if (! ShopBackupService::isValidConfirm($request->input('confirm'))) {
            return response([
                'message' => 'برای بازگردانی مقدار confirm را برابر بازگردانی یا RESTORE بفرستید.',
            ], 422);
        }

        $file = $request->file('file');
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext !== 'zip') {
            return response(['message' => 'فایل باید zip باشد.'], 422);
        }

        $realPath = $file->getRealPath();
        if (! $realPath) {
            return response(['message' => 'آپلود فایل پشتیبان نامعتبر است.'], 422);
        }

        try {
            $result = $backups->restoreFromZip($atelierId, $realPath);
        } catch (RuntimeException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        return response(array_merge([
            'message' => 'بازگردانی با موفقیت انجام شد.',
        ], $result), 200);
    }
}
