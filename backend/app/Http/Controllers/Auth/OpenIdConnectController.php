<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Authentication\Exceptions\UnresolvedExternalIdentity;
use App\Authentication\OpenIdConnect\OpenIdConnectFailure;
use App\Authentication\OpenIdConnect\OpenIdConnectLogin;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The OpenID Connect login lifecycle of ADR-011: login, callback and local
 * logout. Every failure gets the same generic response and nothing is logged.
 */
final class OpenIdConnectController extends Controller
{
    public function login(Request $request, OpenIdConnectLogin $login): RedirectResponse|Response
    {
        if (Auth::check()) {
            return redirect('/');
        }

        try {
            return redirect()->away($login->begin($request->session()));
        } catch (OpenIdConnectFailure) {
            return $this->authenticationFailed();
        }
    }

    public function callback(Request $request, OpenIdConnectLogin $login): RedirectResponse|Response
    {
        $parameters = $request->query();

        // Laravel keeps the full URL of GET requests in the session; the
        // authorization response in the query string must not end up there.
        $request->server->remove('QUERY_STRING');

        try {
            $actor = $login->complete($request->session(), $parameters);
        } catch (OpenIdConnectFailure|UnresolvedExternalIdentity) {
            return $this->authenticationFailed();
        }

        // Regenerates the session and its CSRF token; never "remember me".
        Auth::login($actor, false);

        return redirect('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function authenticationFailed(): Response
    {
        return response('Authentication failed.', 401)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
