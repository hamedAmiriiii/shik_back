<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Throwable $e)
    {
        if ($request instanceof Request && $request->segment(1) === 'api') {
            $request->headers->set('Accept', 'application/json');
        }

        if ($request instanceof Request && ($request->is('api/*') || $request->segment(1) === 'api')) {
            $notFound = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                ? $e
                : (($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException && $e->getPrevious() instanceof \Illuminate\Database\Eloquent\ModelNotFoundException)
                    ? $e->getPrevious()
                    : null);
            if ($notFound) {
                return response()->json([
                    'message' => 'مورد درخواستی یافت نشد.',
                ], 404);
            }
        }

        if ($request instanceof Request && ($request->is('api/*') || $request->segment(1) === 'api') && $e instanceof QueryException) {
            $duplicateMessage = $this->duplicateKeyMessage($e);
            if ($duplicateMessage !== null) {
                return response()->json([
                    'message' => $duplicateMessage,
                ], 422);
            }
        }

        if ($request instanceof Request && $request->is('api/*') && $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException && $e->getStatusCode() === 403) {
            $msg = trim((string) $e->getMessage());
            if ($msg === '' || $msg === 'This action is unauthorized.' || $msg === 'Unauthorized.') {
                $msg = 'شما به این بخش دسترسی ندارید.';
            }
            if ($msg === 'دسترسی غیرمجاز') {
                $msg = 'شما به این مورد دسترسی ندارید.';
            }

            return response()->json([
                'message' => $msg,
                'error' => $msg,
            ], 403);
        }

        return parent::render($request, $e);
    }

    /**
     * Convert an authentication exception into a response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Auth\AuthenticationException  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        // For API requests or requests expecting JSON, always return JSON response
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => 'احراز هویت نشد. توکن را در هدر Authorization: Bearer ... یا در body (access_token / token) بفرستید.',
            ], 401);
        }

        return response()->json([
            'message' => 'احراز هویت نشد. توکن را در هدر Authorization: Bearer ... یا در body (access_token / token) بفرستید.',
        ], 401);
    }

    private function duplicateKeyMessage(QueryException $e): ?string
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $raw = $e->getMessage();
        if ($code !== 1062 && ! str_contains($raw, 'Duplicate entry')) {
            return null;
        }

        if (str_contains($raw, 'products_atelier_id_barcode_unique')) {
            $barcode = null;
            if (preg_match("/Duplicate entry '([^']+)'/", $raw, $match)) {
                $parts = explode('-', $match[1], 2);
                $barcode = $parts[1] ?? null;
            }
            if ($barcode !== null && $barcode !== '') {
                return "بارکد «{$barcode}» قبلاً برای کالای دیگری در همین فروشگاه ثبت شده است.";
            }

            return 'این بارکد قبلاً برای کالای دیگری در همین فروشگاه ثبت شده است.';
        }

        return 'این مورد قبلاً ثبت شده و تکراری است.';
    }

    /**
     * پاسخ JSON اعتبارسنجی با پیام فارسی (اولین خطا در message).
     */
    protected function invalidJson($request, ValidationException $exception)
    {
        $errors = $exception->errors();
        $message = 'داده‌های ارسالی معتبر نیست.';

        foreach ($errors as $fieldErrors) {
            if (!empty($fieldErrors[0])) {
                $message = $fieldErrors[0];
                break;
            }
        }

        return response()->json([
            'message' => $message,
            'errors' => $errors,
        ], $exception->status);
    }
}
