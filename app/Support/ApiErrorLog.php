<?php

namespace App\Support;

use Illuminate\Http\Request;
use Throwable;

/**
 * لاگ مستقل خطاهای 500 در storage/logs/api-errors.log
 * — مستقل از کانال لاگ لاراول؛ اگر نوشتن فایل ممکن نبود به error_log (لاگ php-fpm/کانتینر) می‌رود.
 */
class ApiErrorLog
{
    private static ?string $reservedMemory = null;

    private static bool $fatalHandlerRegistered = false;

    public static function exception(Throwable $e, ?Request $request = null): string
    {
        $id = self::newId();
        $chain = [];
        for ($cur = $e, $depth = 0; $cur && $depth < 5; $cur = $cur->getPrevious(), $depth++) {
            $chain[] = [
                'class' => get_class($cur),
                'message' => mb_substr((string) $cur->getMessage(), 0, 2000),
                'file' => self::relativePath($cur->getFile()),
                'line' => $cur->getLine(),
            ];
        }

        self::write([
            'id' => $id,
            'kind' => 'exception',
            'exceptions' => $chain,
            'request' => self::requestInfo($request),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 25),
        ]);

        return $id;
    }

    /**
     * خطاهای fatal (کمبود حافظه، timeout و ...) که به Handler لاراول نمی‌رسند.
     */
    public static function registerFatalHandler(): void
    {
        if (self::$fatalHandlerRegistered) {
            return;
        }
        self::$fatalHandlerRegistered = true;
        self::$reservedMemory = str_repeat('x', 64 * 1024);

        register_shutdown_function(static function () {
            self::$reservedMemory = null;
            $error = error_get_last();
            if (! $error || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }

            self::write([
                'id' => self::newId(),
                'kind' => 'fatal',
                'exceptions' => [[
                    'class' => 'FatalError',
                    'message' => mb_substr((string) $error['message'], 0, 2000),
                    'file' => self::relativePath((string) $error['file']),
                    'line' => (int) $error['line'],
                ]],
                'request' => [
                    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                    'uri' => $_SERVER['REQUEST_URI'] ?? null,
                    'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                ],
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function write(array $entry): void
    {
        $entry = ['time' => date('Y-m-d H:i:s')] + $entry;
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $written = false;
        try {
            $dir = function_exists('storage_path') ? storage_path('logs') : dirname(__DIR__, 2).'/storage/logs';
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $written = @file_put_contents($dir.'/api-errors.log', $line.PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
        } catch (Throwable $ignored) {
            $written = false;
        }

        if (! $written) {
            @error_log('[api-error] '.$line);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestInfo(?Request $request): array
    {
        if (! $request) {
            return [];
        }

        $userId = null;
        $atelierId = null;
        try {
            $user = $request->user('sanctum');
            if ($user) {
                $userId = $user->id ?? null;
                $atelierId = $user->atelier_id ?? null;
            }
        } catch (Throwable $ignored) {
        }

        return [
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'query' => $request->query(),
            'user_id' => $userId,
            'atelier_id' => $atelierId,
            'ip' => $request->ip(),
        ];
    }

    private static function newId(): string
    {
        return date('ymdHis').'-'.substr(bin2hex(random_bytes(3)), 0, 6);
    }

    private static function relativePath(string $file): string
    {
        $base = function_exists('base_path') ? base_path() : dirname(__DIR__, 2);

        return str_starts_with($file, $base) ? ltrim(substr($file, strlen($base)), '/\\') : $file;
    }
}
