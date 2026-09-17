<?php

declare(strict_types=1);

namespace App\Authentication\OpenIdConnect;

use Facile\JoseVerifier\Exception\ExceptionInterface as TokenValidationException;
use Facile\JoseVerifier\JWK\JwksProviderBuilder;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\ClientInterface as RelyingParty;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Issuer\Metadata\Provider\MetadataProviderBuilder;
use Facile\OpenIDClient\Service\AuthorizationService;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Session\AuthSession;
use Facile\OpenIDClient\Token\IdTokenVerifierBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface as HttpClient;
use Throwable;

/**
 * The only place that talks to `facile-it/php-openid-client`. It discovers the
 * provider, builds the authorization request and, on callback, exchanges the
 * code and has the library validate the ID Token (signature through the
 * provider's JWKS, `iss`, `aud`, `exp`, `iat`, `nbf` and `nonce`), adding the
 * checks the library leaves out. Only the validated `iss` and `sub` leave this
 * class; tokens are discarded.
 */
final class OpenIdConnectClient
{
    private const SCOPE = 'openid';

    private const ID_TOKEN_SIGNING_ALGORITHM = 'RS256';

    private const CLOCK_TOLERANCE_SECONDS = 60;

    private readonly HttpClient $http;

    private readonly HttpFactory $factory;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?? new GuzzleClient(['connect_timeout' => 5, 'timeout' => 10]);
        $this->factory = new HttpFactory();
    }

    /**
     * @throws OpenIdConnectFailure
     */
    public function authorizationUri(OpenIdConnectConfiguration $config, LoginTransaction $transaction): string
    {
        $relyingParty = $this->relyingParty($config);

        return $this->authorizationService()->getAuthorizationUri($relyingParty, [
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'client_id' => $config->clientId,
            'redirect_uri' => $config->redirectUri,
            'state' => $transaction->state,
            'nonce' => $transaction->nonce,
            'code_challenge' => $transaction->codeChallenge(),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Exchanges the authorization code with the transaction's PKCE verifier and
     * returns the identity of the validated ID Token.
     *
     * @throws OpenIdConnectFailure
     */
    public function authenticate(
        OpenIdConnectConfiguration $config,
        LoginTransaction $transaction,
        string $code,
    ): OpenIdConnectIdentity {
        $relyingParty = $this->relyingParty($config);

        try {
            $tokenSet = $this->authorizationService()->callback(
                $relyingParty,
                ['code' => $code],
                $config->redirectUri,
                AuthSession::fromArray([
                    'state' => $transaction->state,
                    'nonce' => $transaction->nonce,
                    'code_verifier' => $transaction->codeVerifier,
                ]),
            );
        } catch (Throwable $error) {
            // Every library failure is an authentication failure; its message
            // may carry claim or nonce values, so it is not propagated.
            throw OpenIdConnectFailure::because(self::isTokenValidationFailure($error)
                ? OpenIdConnectFailure::ID_TOKEN
                : OpenIdConnectFailure::TOKEN_EXCHANGE);
        }

        if ($tokenSet->getIdToken() === null) {
            throw OpenIdConnectFailure::because(OpenIdConnectFailure::ID_TOKEN);
        }

        $claims = $tokenSet->claims();

        // The library compares `nonce` only when the claim is present; a nonce
        // was sent, so it must be there (OpenID Connect Core 1.0, 3.1.3.7).
        $nonce = $claims['nonce'] ?? null;

        if (! is_string($nonce) || ! hash_equals($transaction->nonce, $nonce)) {
            throw OpenIdConnectFailure::because(OpenIdConnectFailure::ID_TOKEN);
        }

        return self::identityFrom($claims, $config->clientId);
    }

    /**
     * @throws OpenIdConnectFailure
     */
    private function relyingParty(OpenIdConnectConfiguration $config): RelyingParty
    {
        try {
            $issuer = (new IssuerBuilder())
                ->setMetadataProviderBuilder(
                    (new MetadataProviderBuilder())
                        ->setHttpClient($this->http)
                        ->setRequestFactory($this->factory)
                        ->setUriFactory($this->factory)
                )
                ->setJwksProviderBuilder(
                    (new JwksProviderBuilder())
                        ->withHttpClient($this->http)
                        ->withRequestFactory($this->factory)
                )
                ->build($config->issuer);
        } catch (Throwable) {
            throw OpenIdConnectFailure::because(OpenIdConnectFailure::PROVIDER_METADATA);
        }

        // The library does not compare the discovered issuer with the
        // configured one (OpenID Connect Discovery 1.0, section 4.3); the ID
        // Token issuer is then checked against this value.
        if ($issuer->getMetadata()->getIssuer() !== $config->issuer) {
            throw OpenIdConnectFailure::because(OpenIdConnectFailure::PROVIDER_METADATA);
        }

        return (new ClientBuilder())
            ->setIssuer($issuer)
            ->setHttpClient($this->http)
            ->setClientMetadata(ClientMetadata::fromArray([
                'client_id' => $config->clientId,
                'client_secret' => $config->clientSecret,
                'token_endpoint_auth_method' => 'client_secret_basic',
                'redirect_uris' => [$config->redirectUri],
                'response_types' => ['code'],
                'id_token_signed_response_alg' => self::ID_TOKEN_SIGNING_ALGORITHM,
            ]))
            ->build();
    }

    private function authorizationService(): AuthorizationService
    {
        return (new AuthorizationServiceBuilder())
            ->setHttpClient($this->http)
            ->setRequestFactory($this->factory)
            ->setIdTokenVerifierBuilder(
                (new IdTokenVerifierBuilder())->setClockTolerance(self::CLOCK_TOLERANCE_SECONDS)
            )
            ->build();
    }

    private static function isTokenValidationFailure(Throwable $error): bool
    {
        return $error instanceof TokenValidationException
            || str_starts_with($error::class, 'Jose\\');
    }

    /**
     * The claims are already validated by the library. Only `iss` and `sub`
     * are used; `azp` must name this client when present, and when there are
     * several audiences (OpenID Connect Core 1.0, section 3.1.3.7).
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws OpenIdConnectFailure
     */
    private static function identityFrom(array $claims, string $clientId): OpenIdConnectIdentity
    {
        $audiences = $claims['aud'] ?? null;
        $authorizedParty = $claims['azp'] ?? null;

        if (is_array($audiences) && count($audiences) > 1 && $authorizedParty === null) {
            throw OpenIdConnectFailure::because(OpenIdConnectFailure::IDENTITY_CLAIMS);
        }

        if ($authorizedParty !== null && $authorizedParty !== $clientId) {
            throw OpenIdConnectFailure::because(OpenIdConnectFailure::IDENTITY_CLAIMS);
        }

        $issuer = $claims['iss'] ?? null;
        $subject = $claims['sub'] ?? null;

        if (! is_string($issuer) || $issuer === '' || ! is_string($subject) || $subject === '') {
            throw OpenIdConnectFailure::because(OpenIdConnectFailure::IDENTITY_CLAIMS);
        }

        return new OpenIdConnectIdentity($issuer, $subject);
    }
}
