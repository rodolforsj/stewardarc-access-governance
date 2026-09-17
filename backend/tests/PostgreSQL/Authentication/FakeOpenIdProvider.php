<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\Authentication;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use OpenSSLAsymmetricKey;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Test-only OpenID Provider served through a Guzzle handler: discovery, token
 * endpoint and JWKS, with an ephemeral RSA key. No network is involved.
 */
final class FakeOpenIdProvider
{
    public const ISSUER = 'https://id.example.test/realms/stewardarc';

    public const CLIENT_ID = 'stewardarc-web';

    public const CLIENT_SECRET = 'test-only-client-secret-long-enough-for-hmac-256';

    public const REDIRECT_URI = 'http://localhost/auth/callback';

    public const KEY_ID = 'test-signing-key';

    public const ACCESS_TOKEN = 'test-only-access-token';

    public const REFRESH_TOKEN = 'test-only-refresh-token';

    /** @var array<string, mixed>|null overrides the discovery document */
    public ?array $discovery = null;

    /** The ID Token returned by the next token request. */
    public ?string $idToken = null;

    /** @var array<string, mixed>|null overrides the whole token response body */
    public ?array $tokenResponse = null;

    public int $tokenStatus = 200;

    public bool $discoveryAvailable = true;

    private readonly OpenSSLAsymmetricKey $signingKey;

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    public function __construct()
    {
        $this->signingKey = self::newKey();
    }

    public static function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            throw new RuntimeException('Unable to create a test RSA key.');
        }

        return $key;
    }

    public function httpClient(): Client
    {
        $stack = HandlerStack::create(fn (RequestInterface $request): PromiseInterface => Create::promiseFor($this->respond($request)));
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    /**
     * @return array<string, mixed>
     */
    public function claims(string $nonce, array $overrides = []): array
    {
        $now = time();

        return array_merge([
            'iss' => self::ISSUER,
            'sub' => '9d6f3c2e-5b1a-4c7d-8e2f-1a2b3c4d5e6f',
            'aud' => self::CLIENT_ID,
            'azp' => self::CLIENT_ID,
            'exp' => $now + 300,
            'iat' => $now,
            'nonce' => $nonce,
        ], $overrides);
    }

    /**
     * An RS256 ID Token, signed with the provider key unless another is given.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $header
     */
    public function signedIdToken(array $claims, ?OpenSSLAsymmetricKey $key = null, array $header = []): string
    {
        $input = self::segment(array_merge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KEY_ID], $header))
            .'.'.self::segment($claims);

        if (! openssl_sign($input, $signature, $key ?? $this->signingKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the test ID Token.');
        }

        return $input.'.'.self::base64Url($signature);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    public static function unsignedToken(array $header, array $claims, string $signature = ''): string
    {
        return self::segment($header).'.'.self::segment($claims).'.'.$signature;
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(string $pathSuffix): array
    {
        $requests = [];

        foreach ($this->history as $transaction) {
            if (str_ends_with($transaction['request']->getUri()->getPath(), $pathSuffix)) {
                $requests[] = $transaction['request'];
            }
        }

        return $requests;
    }

    /**
     * @return list<RequestInterface>
     */
    public function tokenRequests(): array
    {
        return $this->requests('/protocol/openid-connect/token');
    }

    public function requestCount(): int
    {
        return count($this->history);
    }

    private function respond(RequestInterface $request): Response
    {
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/.well-known/openid-configuration')) {
            return $this->discoveryAvailable
                ? self::json($this->discovery ?? $this->discoveryDocument())
                : new Response(404);
        }

        if (str_ends_with($path, '/protocol/openid-connect/token')) {
            return self::json($this->tokenResponse ?? array_filter([
                'access_token' => self::ACCESS_TOKEN,
                'refresh_token' => self::REFRESH_TOKEN,
                'token_type' => 'Bearer',
                'expires_in' => 300,
                'id_token' => $this->idToken,
            ], static fn (mixed $value): bool => $value !== null), $this->tokenStatus);
        }

        if (str_ends_with($path, '/protocol/openid-connect/certs')) {
            return self::json(['keys' => [$this->publicJwk()]]);
        }

        return new Response(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function discoveryDocument(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/protocol/openid-connect/auth',
            'token_endpoint' => self::ISSUER.'/protocol/openid-connect/token',
            'jwks_uri' => self::ISSUER.'/protocol/openid-connect/certs',
            'response_types_supported' => ['code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function publicJwk(): array
    {
        $details = openssl_pkey_get_details($this->signingKey);

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => self::KEY_ID,
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function segment(array $data): string
    {
        return self::base64Url(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
