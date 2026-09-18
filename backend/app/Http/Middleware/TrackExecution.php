<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Observability\OperationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the operational execution of
 * [ADR-012](docs/architecture/adr/0012-operational-observability-baseline.md)
 * for the product surface: one HTTP request is one execution, identified by a
 * new server-generated `execution_id` and named by the route it reached. It
 * wraps the rest of the `web` group, so a failure of the session, of the
 * authentication or of the CSRF protection is already correlated.
 *
 * No correlation identifier is read from the request — `X-Request-ID`,
 * `X-Correlation-ID` and `traceparent` are simply never looked at — and none
 * is returned in the response.
 */
final class TrackExecution
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return OperationContext::runNewExecution(
            $request->route()?->getName(),
            fn (): Response => $next($request),
        );
    }
}
