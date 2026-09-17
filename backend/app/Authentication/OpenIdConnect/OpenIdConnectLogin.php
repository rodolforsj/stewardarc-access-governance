<?php

declare(strict_types=1);

namespace App\Authentication\OpenIdConnect;

use App\Authentication\ActorReferenceResolver;
use App\Authentication\Exceptions\UnresolvedExternalIdentity;
use App\Models\ActorReference;
use Illuminate\Contracts\Session\Session;

/**
 * The StewardArc side of the OpenID Connect login (ADR-011): it owns the login
 * transaction (`state`, `nonce`, PKCE), checks the callback against it and
 * resolves the validated identity to a pre-provisioned Actor Reference. It
 * does not log anyone in; the caller does.
 */
final class OpenIdConnectLogin
{
    public const TRANSACTION_SESSION_KEY = 'oidc_login_transaction';

    public function __construct(
        private readonly OpenIdConnectClient $client,
        private readonly ActorReferenceResolver $resolver,
    ) {
    }

    /**
     * Starts a login, replacing any pending one, and returns the provider's
     * authorization URI.
     *
     * @throws OpenIdConnectFailure
     */
    public function begin(Session $session): string
    {
        $config = $this->configuration();
        $transaction = LoginTransaction::start(now()->getTimestamp());
        $authorizationUri = $this->client->authorizationUri($config, $transaction);

        $session->put(self::TRANSACTION_SESSION_KEY, $transaction->toArray());

        return $authorizationUri;
    }

    /**
     * Completes a login from the callback query parameters. The pending
     * transaction is consumed only once the response is correlated to it by
     * its `state`, whether it carries a code or an error (RFC 6749, sections
     * 4.1.2, 4.1.2.1 and 10.12): a response without the right `state` cannot
     * cancel the pending login.
     *
     * @param  array<string, mixed>  $callback
     *
     * @throws OpenIdConnectFailure
     * @throws UnresolvedExternalIdentity
     */
    public function complete(Session $session, array $callback): ActorReference
    {
        $transaction = LoginTransaction::fromArray($session->get(self::TRANSACTION_SESSION_KEY));

        if ($transaction === null) {
            throw OpenIdConnectFailure::because(OpenIdConnectFailure::MISSING_TRANSACTION);
        }

        if ($transaction->isExpiredAt(now()->getTimestamp())) {
            $session->forget(self::TRANSACTION_SESSION_KEY);

            throw OpenIdConnectFailure::because(OpenIdConnectFailure::EXPIRED_TRANSACTION);
        }

        $state = $callback['state'] ?? null;

        if (! is_string($state) || $state === '') {
            throw OpenIdConnectFailure::because(OpenIdConnectFailure::MISSING_STATE);
        }

        if (! $transaction->matchesState($state)) {
            throw OpenIdConnectFailure::because(OpenIdConnectFailure::STATE_MISMATCH);
        }

        // The response belongs to this login: the transaction is used up.
        $session->forget(self::TRANSACTION_SESSION_KEY);

        if (array_key_exists('error', $callback)) {
            throw OpenIdConnectFailure::because(OpenIdConnectFailure::PROVIDER_ERROR);
        }

        $code = $callback['code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw OpenIdConnectFailure::because(OpenIdConnectFailure::MISSING_CODE);
        }

        $identity = $this->client->authenticate($this->configuration(), $transaction, $code);

        return $this->resolver->resolve($identity->externalIdentityKey());
    }

    /**
     * @throws OpenIdConnectFailure
     */
    private function configuration(): OpenIdConnectConfiguration
    {
        return OpenIdConnectConfiguration::fromArray((array) config('oidc', []));
    }
}
