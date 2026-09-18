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
use Illuminate\Support\Facades\Log;

/**
 * The OpenID Connect login lifecycle of ADR-011: login, callback and local
 * logout. Every failure gets the same generic response, and the only thing
 * recorded about it is the fixed reason of ADR-012: no identity, no callback
 * parameter, no claim and no token.
 */
final class OpenIdConnectController extends Controller
{
    /** An identity that authenticates but has no Actor Reference (ADR-010). */
    private const UNRESOLVED_EXTERNAL_IDENTITY = 'unresolved_external_identity';

    /**
     * Authentication should have been able to operate, and the integration or
     * the configuration could not make it work.
     */
    private const OPERATIONAL_FAILURES = [
        OpenIdConnectFailure::CONFIGURATION,
        OpenIdConnectFailure::PROVIDER_METADATA,
        OpenIdConnectFailure::TOKEN_EXCHANGE,
    ];

    /**
     * Refusals with operational or security value: a response that does not
     * correlate to its login, a token or claims that fail validation, and an
     * identity that is not provisioned. Every other reason is a controlled
     * outcome of the interaction — an abandoned, stale or replayed login, or a
     * refusal the provider itself reports — and is recorded nowhere.
     */
    private const NOTABLE_REFUSALS = [
        OpenIdConnectFailure::STATE_MISMATCH,
        OpenIdConnectFailure::ID_TOKEN,
        OpenIdConnectFailure::IDENTITY_CLAIMS,
        self::UNRESOLVED_EXTERNAL_IDENTITY,
    ];

    public function login(Request $request, OpenIdConnectLogin $login): RedirectResponse|Response
    {
        if (Auth::check()) {
            return redirect('/');
        }

        try {
            return redirect()->away($login->begin($request->session()));
        } catch (OpenIdConnectFailure $failure) {
            return $this->authenticationFailed($failure->reason);
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
        } catch (OpenIdConnectFailure $failure) {
            return $this->authenticationFailed($failure->reason);
        } catch (UnresolvedExternalIdentity) {
            return $this->authenticationFailed(self::UNRESOLVED_EXTERNAL_IDENTITY);
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

    /**
     * The response never varies with the reason: it stays operational and
     * never reaches the client.
     */
    private function authenticationFailed(string $reason): Response
    {
        $this->record($reason);

        return response('Authentication failed.', 401)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * At most one record, carrying the fixed reason and nothing else. It
     * states what the application refused, not who tried or why.
     */
    private function record(string $reason): void
    {
        if (in_array($reason, self::OPERATIONAL_FAILURES, true)) {
            Log::error('OpenID Connect authentication could not be performed.', ['reason' => $reason]);

            return;
        }

        if (in_array($reason, self::NOTABLE_REFUSALS, true)) {
            Log::warning('OpenID Connect authentication was refused.', ['reason' => $reason]);
        }
    }
}
