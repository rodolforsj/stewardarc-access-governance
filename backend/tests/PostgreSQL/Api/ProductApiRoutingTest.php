<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\Api;

use App\Http\Controllers\Api\FollowMyRequestsAndAccessesController;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * ADR-010 and ADR-011: every /api route runs on the web stack and requires the
 * authenticated session, with no CSRF exception and no CORS policy, while the
 * health check and the login routes keep their own configuration.
 */
final class ProductApiRoutingTest extends PostgresTestCase
{
    private const ENDPOINT = '/api/me/requests-and-accesses';

    /** The minimum stack of a session-authenticated API route. */
    private const SESSION_STACK = [
        EncryptCookies::class,
        StartSession::class,
        PreventRequestForgery::class,
        Authenticate::class,
    ];

    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin',
        'Access-Control-Allow-Credentials',
        'Access-Control-Allow-Methods',
        'Access-Control-Allow-Headers',
        'Access-Control-Expose-Headers',
        'Access-Control-Max-Age',
    ];

    public function test_the_endpoint_is_a_named_get_route_without_parameters(): void
    {
        $route = Route::getRoutes()->getByName('api.me.requests-and-accesses');

        $this->assertNotNull($route);
        $this->assertSame('api/me/requests-and-accesses', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame([], $route->parameterNames());
        $this->assertSame(FollowMyRequestsAndAccessesController::class.'@__invoke', $route->getAction('uses'));
    }

    /**
     * Protected by default: whatever routes/api.php declares, or excludes with
     * withoutMiddleware(), must still resolve to the whole session stack, with
     * the session started before authentication reads it.
     */
    public function test_every_api_route_resolves_the_session_csrf_and_authentication_stack(): void
    {
        $apiRoutes = $this->apiRoutes();

        $this->assertNotEmpty($apiRoutes);

        foreach ($apiRoutes as $route) {
            $resolved = $this->resolvedMiddleware($route);

            foreach (self::SESSION_STACK as $middleware) {
                $this->assertContains($middleware, $resolved, "[{$route->uri()}] does not resolve [{$middleware}].");
            }

            $this->assertLessThan(
                array_search(Authenticate::class, $resolved, true),
                array_search(StartSession::class, $resolved, true),
                "[{$route->uri()}] authenticates before the session is started."
            );
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertNotContains('api', $route->gatherMiddleware(), "[{$route->uri()}] uses the stateless api group.");
        }
    }

    /** The check above looks at the resolved stack, so an opt-out cannot go unnoticed. */
    public function test_an_api_route_opting_out_of_authentication_would_be_detected(): void
    {
        Route::middleware(['web', 'auth'])->prefix('api')->group(function (): void {
            Route::get('opt-out-probe', static fn (): array => [])->withoutMiddleware('auth');
        });

        $probe = $this->routeByUri('api/opt-out-probe');

        $this->assertContains('auth', $probe->gatherMiddleware());
        $this->assertNotContains(Authenticate::class, $this->resolvedMiddleware($probe));
    }

    public function test_the_api_has_no_csrf_exception(): void
    {
        $excluded = (new PreventRequestForgery($this->app, $this->app['encrypter']))->getExcludedPaths();

        foreach ($this->apiRoutes() as $route) {
            foreach ($excluded as $pattern) {
                $this->assertFalse(Str::is(trim($pattern, '/'), $route->uri()), "[{$route->uri()}] is excluded by [{$pattern}].");
            }
        }

        $this->assertSame([], array_values(array_filter(
            $excluded,
            static fn (string $pattern): bool => str_starts_with(ltrim($pattern, '/'), 'api'),
        )));
    }

    /** A read needs no CSRF token, even with the check enforced and a cross-site signal. */
    public function test_a_get_request_needs_no_csrf_token(): void
    {
        $this->enforceCsrf();
        Auth::login($this->makeActor('Requester'), false);

        $this->getJson(self::ENDPOINT, ['Sec-Fetch-Site' => 'cross-site'])
            ->assertOk()
            ->assertExactJson(['requests' => []]);
    }

    /** ADR-011: same-origin surface, so no CORS policy at all, not even the framework default. */
    public function test_the_api_emits_no_cors_policy(): void
    {
        $this->assertNotContains(HandleCors::class, $this->app->make(HttpKernel::class)->getGlobalMiddleware());

        $origin = ['Origin' => 'https://attacker.example'];

        $responses = [
            'guest' => $this->getJson(self::ENDPOINT, $origin)->assertUnauthorized(),
            'preflight' => $this->options(self::ENDPOINT, [], $origin + [
                'Access-Control-Request-Method' => 'GET',
                'Access-Control-Request-Headers' => 'X-Requested-With',
            ]),
        ];

        Auth::login($this->makeActor('Requester'), false);
        $responses['authenticated'] = $this->getJson(self::ENDPOINT, $origin)->assertOk();

        foreach ($responses as $case => $response) {
            foreach (self::CORS_HEADERS as $header) {
                $this->assertFalse($response->headers->has($header), "The {$case} response carries [{$header}].");
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherMethods(): array
    {
        return [
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'PATCH' => ['PATCH'],
            'DELETE' => ['DELETE'],
        ];
    }

    #[DataProvider('otherMethods')]
    public function test_other_methods_are_rejected_before_anything_runs(string $method): void
    {
        Auth::login($this->makeActor('Requester'), false);
        $queries = $this->recordQueries();

        $this->call($method, self::ENDPOINT)
            ->assertMethodNotAllowed()
            ->assertHeader('Allow', 'GET, HEAD')
            ->assertHeader('Content-Type', 'application/json');

        $this->assertSame([], $queries());
    }

    public function test_an_unknown_api_path_is_a_json_404(): void
    {
        $this->get('/api/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_the_health_check_stays_public_and_outside_the_api(): void
    {
        $route = $this->routeByUri('up');

        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame([], $route->gatherMiddleware());
        $this->assertNotContains(Authenticate::class, $this->resolvedMiddleware($route));

        $this->get('/up')->assertOk();
    }

    public function test_the_authentication_routes_keep_their_configuration(): void
    {
        $expected = [
            'auth.login' => ['auth/login', ['GET', 'HEAD']],
            'auth.callback' => ['auth/callback', ['GET', 'HEAD']],
            'auth.logout' => ['auth/logout', ['POST']],
        ];

        foreach ($expected as $name => [$uri, $methods]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] is missing.");
            $this->assertSame($uri, $route->uri());
            $this->assertSame($methods, $route->methods());
            $this->assertSame(['web'], $route->gatherMiddleware());
            $this->assertNotContains(Authenticate::class, $this->resolvedMiddleware($route));
        }
    }

    // ------------------------------------------------------------------
    // Helpers

    /**
     * @return list<RoutingRoute>
     */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RoutingRoute $route): bool => $route->uri() === 'api' || str_starts_with($route->uri(), 'api/'),
        ));
    }

    private function routeByUri(string $uri): RoutingRoute
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri) {
                return $route;
            }
        }

        $this->fail("No route has the URI [{$uri}].");
    }

    /**
     * The middleware the router will actually run for the route, after groups,
     * aliases, exclusions and priority.
     *
     * @return list<mixed>
     */
    private function resolvedMiddleware(RoutingRoute $route): array
    {
        // Resolving the HTTP kernel hands its groups, aliases and priority to the router.
        $this->app->make(HttpKernel::class);

        return $this->app->make(Router::class)->gatherRouteMiddleware($route);
    }

    /**
     * @return callable(): list<string> the statements executed so far
     */
    private function recordQueries(): callable
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        return static function () use (&$queries): array {
            return $queries;
        };
    }

    /**
     * Laravel skips the CSRF check while running tests, so the middleware is
     * replaced, for the current test only, by one that does not.
     */
    private function enforceCsrf(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }
}
