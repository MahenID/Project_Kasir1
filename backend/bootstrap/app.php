<?php

use App\Exceptions\DomainException;
use App\Http\Middleware\AssignRequestId;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
        ]);
        $middleware->append(AssignRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Domain exceptions with specific business error codes
        $exceptions->render(function (DomainException $e, Request $request) {
            return ApiResponse::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getFields(),
                $e->getStatusCode()
            );
        });

        // Validation errors
        $exceptions->render(function (ValidationException $e, Request $request) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Data yang dikirimkan tidak valid.',
                $e->errors(),
                422
            );
        });

        // Unauthenticated
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return ApiResponse::error(
                'UNAUTHENTICATED',
                'Sesi tidak valid atau telah berakhir. Silakan login kembali.',
                [],
                401
            );
        });

        // Unauthorized / Forbidden
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            return ApiResponse::error(
                'FORBIDDEN',
                'Anda tidak memiliki izin untuk melakukan tindakan ini.',
                [],
                403
            );
        });

        // Resource Not Found
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Sumber daya yang dicari tidak ditemukan.',
                [],
                404
            );
        });

        // Rate Limit Exceeded
        $exceptions->render(function (ThrottleRequestsException|TooManyRequestsHttpException $e, Request $request) {
            return ApiResponse::error(
                'RATE_LIMIT_EXCEEDED',
                'Terlalu banyak permintaan. Silakan tunggu beberapa saat.',
                [],
                429
            );
        });
    })->create();
