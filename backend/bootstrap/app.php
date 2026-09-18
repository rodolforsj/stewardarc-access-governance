<?php

use App\AccessGovernance\Exceptions\AccessDecisionRuleViolation;
use App\AccessGovernance\Exceptions\AccessRequestRuleViolation;
use App\AccessGovernance\Exceptions\GrantConfirmationViolation;
use App\AccessGovernance\Exceptions\RevocationConfirmationViolation;
use App\Http\Middleware\TrackExecution;
use App\Observability\DatabaseFailure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

// ADR-012: a stack trace keeps files, lines and functions but never the values
// passed to them, so no justification, identity or identifier reaches an
// operational record through the trace of a reported exception.
ini_set('zend.exception_ignore_args', '1');

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
        // The operational execution of ADR-012 wraps the whole product
        // surface, the login routes included, so that a failure of the
        // session, of the authentication or of the CSRF protection is already
        // correlated. The health check has no middleware group and stays out.
        $middleware->web(prepend: TrackExecution::class);

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

        // ADR-012: a refusal by a business rule is a functional outcome, not
        // an operational incident. The framework already ignores the
        // authentication, authorization, CSRF, validation and not-found
        // failures in the same way, so they are not repeated here.
        $exceptions->dontReport([
            AccessRequestRuleViolation::class,
            AccessDecisionRuleViolation::class,
            GrantConfirmationViolation::class,
            RevocationConfirmationViolation::class,
        ]);

        // ADR-012: one operational record per failure. A critical Action
        // reports while its own operation is still active and rethrows the
        // same instance, which an outer boundary would otherwise report again.
        $exceptions->dontReportDuplicates();

        // ADR-012: the raw report of a database failure carries the statement
        // with its binding values, the driver's own message and the connection
        // details. Returning false suppresses it and keeps only this sanitized
        // record, whose execution and operation come from the context.
        $exceptions->report(function (QueryException $failure): bool {
            Log::error(DatabaseFailure::MESSAGE, DatabaseFailure::context($failure));

            return false;
        });
    })->create();
