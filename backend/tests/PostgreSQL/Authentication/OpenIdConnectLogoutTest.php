<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\Authentication;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * ADR-011: the local logout and its CSRF protection.
 */
final class OpenIdConnectLogoutTest extends PostgresTestCase
{
    public function test_logout_ends_the_local_session(): void
    {
        $actor = $this->makeActor('Requester');
        Auth::login($actor, false);
        session()->put('unrelated', 'value');
        $sessionId = session()->getId();
        $token = session()->token();

        $response = $this->post('/auth/logout');

        $response->assertRedirect('/');
        $this->assertFalse(Auth::check());
        $this->assertFalse(session()->has(Auth::guard()->getName()));
        $this->assertFalse(session()->has('unrelated'));
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->assertNotNull($actor->fresh());

        // A fresh guard over the same session is not authenticated either.
        Auth::forgetGuards();
        $this->assertNull(Auth::user());
    }

    public function test_logout_without_an_authenticated_actor_is_harmless(): void
    {
        $this->post('/auth/logout')->assertRedirect('/');

        $this->assertFalse(Auth::check());
    }

    public function test_logout_is_not_available_through_get(): void
    {
        Auth::login($this->makeActor('Requester'), false);

        $this->get('/auth/logout')->assertStatus(405);

        $this->assertTrue(Auth::check());
    }

    public function test_logout_is_a_web_route_without_a_csrf_exception(): void
    {
        $route = Route::getRoutes()->getByName('auth.logout');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains(PreventRequestForgery::class, $this->app->make(HttpKernel::class)->getMiddlewareGroups()['web']);
        $this->assertNotContains('auth/logout', (new PreventRequestForgery($this->app, $this->app['encrypter']))->getExcludedPaths());
    }

    /**
     * Laravel skips the CSRF check while running tests, so the middleware is
     * replaced, for this test only, by one that does not.
     */
    public function test_logout_requires_a_valid_csrf_token(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        Auth::login($this->makeActor('Requester'), false);
        $token = session()->token();

        // No token and no same-origin signal: rejected, still logged in.
        $this->post('/auth/logout')->assertStatus(419);
        $this->assertTrue(Auth::check());

        $this->post('/auth/logout', [], ['X-CSRF-TOKEN' => 'not-the-token'])->assertStatus(419);
        $this->post('/auth/logout', ['_token' => 'not-the-token'])->assertStatus(419);
        $this->post('/auth/logout', [], ['Sec-Fetch-Site' => 'cross-site'])->assertStatus(419);
        $this->post('/auth/logout', [], ['Sec-Fetch-Site' => 'same-site'])->assertStatus(419);
        $this->assertTrue(Auth::check());

        // The session token is accepted.
        $this->post('/auth/logout', [], ['X-CSRF-TOKEN' => $token])->assertRedirect('/');
        $this->assertFalse(Auth::check());
    }

    public function test_a_same_origin_browser_request_passes_the_csrf_check(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        Auth::login($this->makeActor('Requester'), false);

        $this->post('/auth/logout', [], ['Sec-Fetch-Site' => 'same-origin'])->assertRedirect('/');
        $this->assertFalse(Auth::check());
    }
}
