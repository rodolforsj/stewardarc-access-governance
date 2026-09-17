<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The product API (ADR-010, ADR-011) is a stateful session API: every
        // route in routes/api.php gets the web middleware group (cookies,
        // session, CSRF) and authentication. Laravel's own "api" group has no
        // session, so it is deliberately not used.
        then: function (): void {
            Route::middleware(['web', 'auth'])
                ->prefix('api')
                ->name('api.')
                ->group(__DIR__.'/../routes/api.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Guests are never redirected: an unauthenticated API request gets a
        // JSON 401 from the exception handler, whatever its Accept header.
        $middleware->redirectGuestsTo(null);

        // The browser surface is same-origin (ADR-011), so no CORS policy is
        // emitted at all, not even the framework's default one for api/*.
        $middleware->remove(HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
