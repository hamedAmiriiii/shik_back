<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LaravelLogController extends Controller
{
    /**
     * وضعیت و لیست فایل‌های لاگ در storage/logs — فقط ادمین سامانه.
     */
    public function index(Request $request)
    {
        $this->requirePlatformAdmin($request);

        $dir = $this->logsDirectory();
        $files = [];
        if (is_dir($dir)) {
            foreach (File::files($dir) as $file) {
                $name = $file->getFilename();
                if (! $this->isAllowedLogName($name)) {
                    continue;
                }
                $files[] = $this->fileMeta($name, $file->getPathname());
            }
        }

        usort($files, function (array $a, array $b) {
            return ($b['modified_at_unix'] ?? 0) <=> ($a['modified_at_unix'] ?? 0);
        });

        foreach ($files as &$row) {
            unset($row['modified_at_unix']);
        }
        unset($row);

        return response()->json([
            'directory' => 'storage/logs',
            'files' => $files,
        ]);
    }

    /**
     * دانلود یک فایل لاگ (فقط نام فایل داخل storage/logs).
     */
    public function download(Request $request): BinaryFileResponse
    {
        $this->requirePlatformAdmin($request);

        $path = $this->resolveLogPath((string) $request->query('file', 'laravel.log'));
        if (! is_file($path)) {
            abort(response()->json(['message' => 'فایل لاگ پیدا نشد.'], 404));
        }

        $name = basename($path);

        return response()->download($path, $name, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * حذف یک فایل لاگ (یا خالی‌کردن اگر حذف ممکن نبود).
     */
    public function destroy(Request $request)
    {
        $this->requirePlatformAdmin($request);

        $name = (string) $request->input('file', $request->query('file', 'laravel.log'));
        $path = $this->resolveLogPath($name);
        if (! is_file($path)) {
            return response()->json(['message' => 'فایل لاگ پیدا نشد.'], 404);
        }

        $deleted = false;
        $cleared = false;
        try {
            $deleted = @unlink($path);
        } catch (\Throwable $e) {
            $deleted = false;
        }

        if (! $deleted) {
            $cleared = @file_put_contents($path, '') !== false;
            if (! $cleared) {
                return response()->json(['message' => 'حذف یا خالی‌کردن فایل لاگ ممکن نشد.'], 500);
            }
        }

        return response()->json([
            'message' => $deleted ? 'فایل لاگ حذف شد.' : 'محتوای فایل لاگ خالی شد.',
            'file' => basename($path),
            'deleted' => $deleted,
            'cleared' => $cleared,
        ]);
    }

    protected function requirePlatformAdmin(Request $request): User
    {
        $actor = $this->shopRequestActor($request);
        if ($actor instanceof Customer) {
            abort(response()->json(['message' => 'این عملیات فقط برای ادمین است.'], 403));
        }
        if (! $actor instanceof User) {
            abort(response()->json(['message' => 'لطفاً وارد شوید.'], 401));
        }
        if (! $actor->roles()->where('id', User::USER_TYPE_KEY['ادمین'])->exists()) {
            abort(response()->json(['message' => 'فقط ادمین سامانه می‌تواند لاگ سرور را ببیند.'], 403));
        }

        return $actor;
    }

    protected function logsDirectory(): string
    {
        return storage_path('logs');
    }

    protected function isAllowedLogName(string $name): bool
    {
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9._-]+\.log$/u', $name);
    }

    protected function resolveLogPath(string $name): string
    {
        $name = basename(trim($name));
        if (! $this->isAllowedLogName($name)) {
            abort(response()->json(['message' => 'نام فایل لاگ نامعتبر است.'], 422));
        }

        $dir = realpath($this->logsDirectory()) ?: $this->logsDirectory();
        $path = $dir.DIRECTORY_SEPARATOR.$name;
        $real = realpath($path);
        if ($real !== false) {
            $dirReal = realpath($dir) ?: $dir;
            if (! str_starts_with($real, $dirReal)) {
                abort(response()->json(['message' => 'دسترسی به این مسیر مجاز نیست.'], 422));
            }

            return $real;
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fileMeta(string $name, string $path): array
    {
        $size = @filesize($path);
        $mtime = @filemtime($path);

        return [
            'name' => $name,
            'size' => is_int($size) ? $size : 0,
            'size_label' => $this->formatBytes(is_int($size) ? $size : 0),
            'modified_at' => is_int($mtime) ? date('Y-m-d H:i:s', $mtime) : null,
            'modified_at_unix' => is_int($mtime) ? $mtime : 0,
            'exists' => is_file($path),
        ];
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 2).' MB';
    }
}
