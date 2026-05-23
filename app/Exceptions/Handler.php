<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
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
