<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\Authentication;

use App\Authentication\Exceptions\UnresolvedExternalIdentity;
use App\Authentication\ExternalIdentityKey;
use App\Authentication\OpenIdConnect\LoginTransaction;
use App\Authentication\OpenIdConnect\OpenIdConnectClient;
use App\Authentication\OpenIdConnect\OpenIdConnectFailure;
use App\Authentication\OpenIdConnect\OpenIdConnectLogin;
use App\Models\ActorReference;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * ADR-011: the OpenID Connect login and callback, against a fake provider.
 */
final class OpenIdConnectLoginTest extends PostgresTestCase
{
    private const CODE = 'test-authorization-code';

    private FakeOpenIdProvider $provider;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['oidc' => [
            'issuer' => FakeOpenIdProvider::ISSUER,
            'client_id' => FakeOpenIdProvider::CLIENT_ID,
            'client_secret' => FakeOpenIdProvider::CLIENT_SECRET,
            'redirect_uri' => FakeOpenIdProvider::REDIRECT_URI,
        ]]);

        $this->provider = new FakeOpenIdProvider();
        $this->app->instance(OpenIdConnectClient::class, new OpenIdConnectClient($this->provider->httpClient()));

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // GET /auth/login

    public function test_login_redirects_to_the_provider_with_an_authorization_code_request(): void
    {
        $response = $this->get('/auth/login');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(FakeOpenIdProvider::ISSUER.'/protocol/openid-connect/auth?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $transaction = $this->transaction();

        $this->assertEqualsCanonicalizing(
            ['response_type', 'scope', 'client_id', 'redirect_uri', 'state', 'nonce', 'code_challenge', 'code_challenge_method'],
            array_keys($query)
        );
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid', $query['scope']);
        $this->assertSame(FakeOpenIdProvider::CLIENT_ID, $query['client_id']);
        $this->assertSame(FakeOpenIdProvider::REDIRECT_URI, $query['redirect_uri']);
        $this->assertSame($transaction['state'], $query['state']);
        $this->assertSame($transaction['nonce'], $query['nonce']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(
            FakeOpenIdProvider::base64Url(hash('sha256', $transaction['code_verifier'], true)),
            $query['code_challenge']
        );

        // The verifier and the client secret never leave the backend.
        $this->assertStringNotContainsString($transaction['code_verifier'], $location);
        $this->assertStringNotContainsString(FakeOpenIdProvider::CLIENT_SECRET, $location);
        $this->assertStringNotContainsString(FakeOpenIdProvider::CLIENT_SECRET, $this->sessionDump());
        $this->assertSame([], $this->provider->tokenRequests());
    }

    public function test_login_stores_one_fresh_transaction_in_the_session(): void
    {
        Carbon::setTestNow('2026-09-17 10:00:00');

        $this->get('/auth/login')->assertRedirect();
        $first = $this->transaction();

        $this->assertSame(['state', 'nonce', 'code_verifier', 'created_at'], array_keys($first));
        $this->assertSame(Carbon::now()->getTimestamp(), $first['created_at']);

        foreach (['state', 'nonce', 'code_verifier'] as $key) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first[$key]);
        }

        $this->assertCount(3, array_unique([$first['state'], $first['nonce'], $first['code_verifier']]));

        // A new login replaces the pending one.
        $this->get('/auth/login')->assertRedirect();
        $second = $this->transaction();

        $this->assertNotSame($first['state'], $second['state']);
        $this->assertNotSame($first['nonce'], $second['nonce']);
        $this->assertNotSame($first['code_verifier'], $second['code_verifier']);
        $this->assertFalse(Auth::check());
    }

    public function test_an_authenticated_actor_is_not_sent_to_the_provider_again(): void
    {
        Auth::login($this->provisionedActor(), false);
        $before = $this->provider->requestCount();

        $this->get('/auth/login')->assertRedirect('/');

        $this->assertFalse(session()->has(OpenIdConnectLogin::TRANSACTION_SESSION_KEY));
        $this->assertSame($before, $this->provider->requestCount());
    }

    public function test_login_fails_closed_without_configuration(): void
    {
        foreach (['issuer', 'client_id', 'client_secret', 'redirect_uri'] as $key) {
            config(["oidc.{$key}" => null]);

            $this->assertAuthenticationFailed($this->get('/auth/login'));
            $this->assertFalse(session()->has(OpenIdConnectLogin::TRANSACTION_SESSION_KEY));

            config(["oidc.{$key}" => $key === 'issuer' ? FakeOpenIdProvider::ISSUER : 'restored']);
        }

        $this->assertSame(0, $this->provider->requestCount());
    }

    public function test_login_fails_closed_when_the_provider_metadata_is_unusable(): void
    {
        $this->provider->discoveryAvailable = false;
        $this->assertAuthenticationFailed($this->get('/auth/login'));

        // A discovery document for another issuer is refused (mix-up protection).
        $this->provider->discoveryAvailable = true;
        $this->provider->discovery = ['issuer' => 'https://other.example.test'] + $this->discoveryFor(FakeOpenIdProvider::ISSUER);
        $this->assertAuthenticationFailed($this->get('/auth/login'));

        $this->assertFalse(session()->has(OpenIdConnectLogin::TRANSACTION_SESSION_KEY));
    }

    // ------------------------------------------------------------------
    // GET /auth/callback — success

    public function test_a_valid_callback_logs_the_provisioned_actor_in(): void
    {
        $actor = $this->provisionedActor();
        $this->get('/auth/login');
        $transaction = $this->transaction();
        $this->provider->idToken = $this->provider->signedIdToken($this->provider->claims($transaction['nonce']));

        $response = $this->sendCallback(['code' => self::CODE, 'state' => $transaction['state']]);

        $response->assertRedirect('/');
        $this->assertTrue(Auth::check());
        $this->assertSame($actor->id, Auth::id());
        $this->assertFalse(Auth::viaRemember());
        $this->assertFalse(session()->has(OpenIdConnectLogin::TRANSACTION_SESSION_KEY));
        $response->assertCookieMissing(Auth::guard()->getRecallerName());

        // The code was exchanged once, with the PKCE verifier and HTTP Basic client authentication.
        $tokenRequests = $this->provider->tokenRequests();
        $this->assertCount(1, $tokenRequests);
        $this->assertSame('POST', $tokenRequests[0]->getMethod());
        $this->assertSame(
            'Basic '.base64_encode(urlencode(FakeOpenIdProvider::CLIENT_ID).':'.urlencode(FakeOpenIdProvider::CLIENT_SECRET)),
            $tokenRequests[0]->getHeaderLine('Authorization')
        );
        parse_str((string) $tokenRequests[0]->getBody(), $form);
        $this->assertSame([
            'grant_type' => 'authorization_code',
            'code' => self::CODE,
            'redirect_uri' => FakeOpenIdProvider::REDIRECT_URI,
            'code_verifier' => $transaction['code_verifier'],
        ], $form);

        // The ID Token signature was checked against the provider's JWKS.
        $this->assertNotEmpty($this->provider->requests('/protocol/openid-connect/certs'));
    }

    public function test_after_login_the_session_holds_only_laravel_artifacts(): void
    {
        $actor = $this->provisionedActor();
        $this->get('/auth/login');
        $transaction = $this->transaction();
        $claims = $this->provider->claims($transaction['nonce']);
        $this->provider->idToken = $this->provider->signedIdToken($claims);
        $tokenBefore = session()->token();

        $response = $this->sendCallback(['code' => self::CODE, 'state' => $transaction['state']]);

        $response->assertRedirect('/');
        $session = session()->all();
        $guardKey = Auth::guard()->getName();

        $this->assertEqualsCanonicalizing([$guardKey, '_token', '_previous', '_flash'], array_keys($session));
        $this->assertSame($actor->id, $session[$guardKey]);
        $this->assertNotSame($tokenBefore, session()->token());
        $this->assertSame(url('/auth/callback'), $session['_previous']['url']);

        $dump = $this->sessionDump();

        foreach ([
            $this->provider->idToken,
            FakeOpenIdProvider::ACCESS_TOKEN,
            FakeOpenIdProvider::REFRESH_TOKEN,
            FakeOpenIdProvider::CLIENT_SECRET,
            self::CODE,
            $transaction['state'],
            $transaction['nonce'],
            $transaction['code_verifier'],
            $claims['iss'],
            $claims['sub'],
            $actor->external_identity_key,
            OpenIdConnectLogin::TRANSACTION_SESSION_KEY,
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dump);
        }

        foreach ([$this->provider->idToken, FakeOpenIdProvider::ACCESS_TOKEN, FakeOpenIdProvider::REFRESH_TOKEN] as $token) {
            $this->assertStringNotContainsString($token, (string) $response->getContent());
            $this->assertStringNotContainsString($token, (string) $response->headers);
        }

        $this->assertSame([], $this->logged);
    }

    /** Only (iss, sub) identify the actor; other claims carry no identity or authority. */
    public function test_additional_claims_are_ignored_for_identity_and_authority(): void
    {
        $actor = $this->provisionedActor();
        $governanceLooking = $this->makeActor('governance@example.test');
        $this->makeGovernanceMembership($governanceLooking);
        $snapshot = $this->authoritySnapshot();

        $this->get('/auth/login');
        $transaction = $this->transaction();
        $this->provider->idToken = $this->provider->signedIdToken($this->provider->claims($transaction['nonce'], [
            'email' => 'governance@example.test',
            'preferred_username' => $governanceLooking->external_identity_key,
            'name' => 'governance@example.test',
            'roles' => ['governance', 'resource_owner'],
            'groups' => ['governance'],
            'realm_access' => ['roles' => ['admin']],
            'scope' => 'openid admin',
        ]));

        $this->sendCallback(['code' => self::CODE, 'state' => $transaction['state']])->assertRedirect('/');

        $this->assertSame($actor->id, Auth::id());
        $this->assertFalse(DB::table('governance_memberships')->where('actor_reference_id', $actor->id)->exists());
        $this->assertSame($snapshot, $this->authoritySnapshot());
    }

    // ------------------------------------------------------------------
    // GET /auth/callback — failures of the transaction and the response

    public function test_a_callback_without_a_pending_transaction_fails(): void
    {
        $this->provisionedActor();

        $this->assertAuthenticationFailed($this->sendCallback(['code' => self::CODE, 'state' => 'any-state']));
        $this->assertSame(0, $this->provider->requestCount());
    }

    public function test_an_expired_transaction_fails_and_is_consumed(): void
    {
        $this->provisionedActor();
        Carbon::setTestNow('2026-09-17 10:00:00');
        $this->get('/auth/login');
        $transaction = $this->transaction();

        // Still valid at the limit.
        Carbon::setTestNow('2026-09-17 10:10:00');
        $this->assertFalse(LoginTransaction::fromArray($transaction)->isExpiredAt(Carbon::now()->getTimestamp()));

        Carbon::setTestNow('2026-09-17 10:10:01');
        $this->assertAuthenticationFailed($this->sendCallback(['code' => self::CODE, 'state' => $transaction['state']]));
        $this->assertSame([], $this->provider->tokenRequests());
        $this->assertFalse(session()->has(OpenIdConnectLogin::TRANSACTION_SESSION_KEY));
    }

    /**
     * A response is correlated to the pending login only by its exact `state`
     * (RFC 6749, sections 4.1.2 and 4.1.2.1). Only a correlated response, with
     * a code or an error, uses up the transaction; any other response fails
     * without cancelling the pending login.
     */
    #[DataProvider('rejectedCallbacks')]
    public function test_an_unusable_authorization_response_fails_before_the_token_exchange(
        string $case,
        string $reason,
        bool $consumesTransaction,
    ): void {
        $actor = $this->provisionedActor();
        $this->get('/auth/login');
        $transaction = $this->transaction();

        $response = $this->sendCallback($this->authorizationResponse($case, $transaction['state']));

        $this->assertAuthenticationFailed($response);
        $this->assertSame([], $this->provider->tokenRequests());
        $this->assertStringNotContainsString('?', (string) session('_previous.url'));
        $this->assertSame([], $this->logged);

        if ($consumesTransaction) {
            $this->assertFalse(session()->has(OpenIdConnectLogin::TRANSACTION_SESSION_KEY));
            $this->assertStringNotContainsString($transaction['state'], $this->sessionDump());

            // Even the right response can no longer use the transaction.
            $this->assertAuthenticationFailed($this->sendCallback(['code' => self::CODE, 'state' => $transaction['state']]));
            $this->assertSame([], $this->provider->tokenRequests());
        } else {
            $this->assertSame($transaction, $this->transaction());

            // The pending login still completes with its own response, once.
            $this->provider->idToken = $this->provider->signedIdToken($this->provider->claims($transaction['nonce']));
            $this->sendCallback(['code' => self::CODE, 'state' => $transaction['state']])->assertRedirect('/');
            $this->assertSame($actor->id, Auth::id());
            $this->assertCount(1, $this->provider->tokenRequests());
            $this->assertFalse(session()->has(OpenIdConnectLogin::TRANSACTION_SESSION_KEY));

            Auth::logout();
        }

        $tokenRequests = count($this->provider->tokenRequests());

        // In the login service, the intended check is the one that failed.
        $this->assertFailureReason($reason, function (OpenIdConnectLogin $login) use ($case, $consumesTransaction): void {
            $login->begin(session()->driver());
            $pending = $this->transaction();

            try {
                $login->complete(session()->driver(), $this->authorizationResponse($case, $pending['state']));
            } finally {
                if ($consumesTransaction) {
                    $this->assertFalse(session()->has(OpenIdConnectLogin::TRANSACTION_SESSION_KEY));
                } else {
                    $this->assertSame($pending, $this->transaction());
                }
            }
        }, transactionRemains: ! $consumesTransaction);

        $this->assertCount($tokenRequests, $this->provider->tokenRequests());
        $this->assertFalse(Auth::check());
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizationResponse(string $case, string $state): array
    {
        $otherState = FakeOpenIdProvider::base64Url(random_bytes(32));

        return match ($case) {
            'code without state' => ['code' => self::CODE],
            'code with an empty state' => ['code' => self::CODE, 'state' => ''],
            'code with a list as state' => ['code' => self::CODE, 'state' => [$state]],
            'code with a different state' => ['code' => self::CODE, 'state' => $otherState],
            'code with a longer state' => ['code' => self::CODE, 'state' => $state.'x'],
            'error without state' => ['error' => 'access_denied'],
            'error with an empty state' => ['error' => 'access_denied', 'state' => ''],
            'error with a different state' => ['error' => 'access_denied', 'state' => $otherState],
            'error with the right state' => ['error' => 'access_denied', 'error_description' => 'The user refused.', 'state' => $state],
            'error and code with the right state' => ['error' => 'server_error', 'code' => self::CODE, 'state' => $state],
            'right state without code' => ['state' => $state],
            'right state with an empty code' => ['code' => '', 'state' => $state],
            'right state with a list as code' => ['code' => [self::CODE], 'state' => $state],
        };
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function rejectedCallbacks(): array
    {
        $cases = [
            // Not correlated: the pending transaction is kept.
            'code without state' => [OpenIdConnectFailure::MISSING_STATE, false],
            'code with an empty state' => [OpenIdConnectFailure::MISSING_STATE, false],
            'code with a list as state' => [OpenIdConnectFailure::MISSING_STATE, false],
            'code with a different state' => [OpenIdConnectFailure::STATE_MISMATCH, false],
            'code with a longer state' => [OpenIdConnectFailure::STATE_MISMATCH, false],
            'error without state' => [OpenIdConnectFailure::MISSING_STATE, false],
            'error with an empty state' => [OpenIdConnectFailure::MISSING_STATE, false],
            'error with a different state' => [OpenIdConnectFailure::STATE_MISMATCH, false],
            // Correlated by the right state: the transaction is used up.
            'error with the right state' => [OpenIdConnectFailure::PROVIDER_ERROR, true],
            'error and code with the right state' => [OpenIdConnectFailure::PROVIDER_ERROR, true],
            'right state without code' => [OpenIdConnectFailure::MISSING_CODE, true],
            'right state with an empty code' => [OpenIdConnectFailure::MISSING_CODE, true],
            'right state with a list as code' => [OpenIdConnectFailure::MISSING_CODE, true],
        ];

        $data = [];

        foreach ($cases as $case => [$reason, $consumesTransaction]) {
            $data[$case] = [$case, $reason, $consumesTransaction];
        }

        return $data;
    }

    public function test_a_callback_cannot_be_replayed(): void
    {
        $actor = $this->provisionedActor();
        $this->get('/auth/login');
        $transaction = $this->transaction();
        $this->provider->idToken = $this->provider->signedIdToken($this->provider->claims($transaction['nonce']));
        $query = ['code' => self::CODE, 'state' => $transaction['state']];

        $this->sendCallback($query)->assertRedirect('/');
        Auth::logout();

        $this->assertAuthenticationFailed($this->sendCallback($query));
        $this->assertFalse(Auth::check());
        $this->assertCount(1, $this->provider->tokenRequests());
        $this->assertNotNull($actor->fresh());
    }

    public function test_an_unknown_identity_is_not_logged_in_or_created(): void
    {
        $this->makeActor('Someone else');
        $before = DB::table('actor_references')->orderBy('id')->get()->all();
        $this->get('/auth/login');
        $transaction = $this->transaction();
        $this->provider->idToken = $this->provider->signedIdToken($this->provider->claims($transaction['nonce'], [
            'sub' => 'not-provisioned',
        ]));

        $this->assertAuthenticationFailed($this->sendCallback(['code' => self::CODE, 'state' => $transaction['state']]));

        $this->assertCount(1, $this->provider->tokenRequests());
        $this->assertEquals($before, DB::table('actor_references')->orderBy('id')->get()->all());
        $this->assertStringNotContainsString('not-provisioned', $this->sessionDump());
    }

    public function test_the_failure_response_reveals_nothing(): void
    {
        $this->provisionedActor();
        $this->get('/auth/login');
        $transaction = $this->transaction();
        $this->provider->idToken = $this->provider->signedIdToken($this->provider->claims('another-nonce'));

        $response = $this->sendCallback(['code' => self::CODE, 'state' => $transaction['state']]);

        $this->assertAuthenticationFailed($response);

        foreach ([self::CODE, $transaction['state'], $transaction['nonce'], $transaction['code_verifier'], 'another-nonce', $this->provider->idToken, FakeOpenIdProvider::ACCESS_TOKEN, FakeOpenIdProvider::CLIENT_SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $response->getContent());
            $this->assertStringNotContainsString($secret, (string) $response->headers);
            $this->assertStringNotContainsString($secret, $this->sessionDump());
        }

        $this->assertSame([], $this->logged);
    }

    // ------------------------------------------------------------------
    // GET /auth/callback — failures of the token exchange and the ID Token

    #[DataProvider('rejectedTokens')]
    public function test_an_invalid_token_response_fails_closed(string $case, string $reason): void
    {
        $actor = $this->provisionedActor();

        // Through HTTP: a generic failure, nothing kept, nobody logged in.
        $this->get('/auth/login');
        $transaction = $this->transaction();
        $this->arrangeTokenResponse($case, $transaction['nonce']);

        $response = $this->sendCallback(['code' => self::CODE, 'state' => $transaction['state']]);

        $this->assertAuthenticationFailed($response);
        $this->assertFalse(session()->has(OpenIdConnectLogin::TRANSACTION_SESSION_KEY));
        $this->assertStringNotContainsString($transaction['nonce'], $this->sessionDump());
        $this->assertStringNotContainsString($transaction['code_verifier'], $this->sessionDump());
        $this->assertNotNull($actor->fresh());
        $this->assertSame([], $this->logged);

        // Through the login service: the intended check is the one that failed.
        $this->assertFailureReason($reason, function (OpenIdConnectLogin $login) use ($case): void {
            $login->begin(session()->driver());
            $transaction = $this->transaction();
            $this->arrangeTokenResponse($case, $transaction['nonce']);
            $login->complete(session()->driver(), ['code' => self::CODE, 'state' => $transaction['state']]);
        });
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedTokens(): array
    {
        return [
            'signature from another key' => ['signature from another key', OpenIdConnectFailure::ID_TOKEN],
            'unknown key id' => ['unknown key id', OpenIdConnectFailure::ID_TOKEN],
            'tampered payload' => ['tampered payload', OpenIdConnectFailure::ID_TOKEN],
            'unsigned token' => ['unsigned token', OpenIdConnectFailure::ID_TOKEN],
            'HMAC with the client secret' => ['HMAC with the client secret', OpenIdConnectFailure::ID_TOKEN],
            'wrong issuer' => ['wrong issuer', OpenIdConnectFailure::ID_TOKEN],
            'wrong audience' => ['wrong audience', OpenIdConnectFailure::ID_TOKEN],
            'several audiences without azp' => ['several audiences without azp', OpenIdConnectFailure::IDENTITY_CLAIMS],
            'azp of another client' => ['azp of another client', OpenIdConnectFailure::IDENTITY_CLAIMS],
            'expired' => ['expired', OpenIdConnectFailure::ID_TOKEN],
            'issued in the future' => ['issued in the future', OpenIdConnectFailure::ID_TOKEN],
            'not yet valid' => ['not yet valid', OpenIdConnectFailure::ID_TOKEN],
            'wrong nonce' => ['wrong nonce', OpenIdConnectFailure::ID_TOKEN],
            'missing nonce' => ['missing nonce', OpenIdConnectFailure::ID_TOKEN],
            'missing subject' => ['missing subject', OpenIdConnectFailure::ID_TOKEN],
            'empty subject' => ['empty subject', OpenIdConnectFailure::IDENTITY_CLAIMS],
            'non-string subject' => ['non-string subject', OpenIdConnectFailure::IDENTITY_CLAIMS],
            'no ID Token' => ['no ID Token', OpenIdConnectFailure::ID_TOKEN],
            'token endpoint error' => ['token endpoint error', OpenIdConnectFailure::TOKEN_EXCHANGE],
            'token endpoint failure' => ['token endpoint failure', OpenIdConnectFailure::TOKEN_EXCHANGE],
            'provider metadata changed' => ['provider metadata changed', OpenIdConnectFailure::PROVIDER_METADATA],
        ];
    }

    public function test_the_failure_reasons_of_the_transaction_and_the_identity(): void
    {
        $this->assertFailureReason(OpenIdConnectFailure::MISSING_TRANSACTION, function (OpenIdConnectLogin $login): void {
            $login->complete(session()->driver(), ['code' => self::CODE, 'state' => 'any']);
        });

        $this->assertFailureReason(OpenIdConnectFailure::EXPIRED_TRANSACTION, function (OpenIdConnectLogin $login): void {
            Carbon::setTestNow('2026-09-17 10:00:00');
            $login->begin(session()->driver());
            $state = $this->transaction()['state'];
            Carbon::setTestNow('2026-09-17 10:10:01');
            $login->complete(session()->driver(), ['code' => self::CODE, 'state' => $state]);
        });
        Carbon::setTestNow();

        $this->assertFailureReason(OpenIdConnectFailure::CONFIGURATION, function (OpenIdConnectLogin $login): void {
            config(['oidc.client_secret' => '']);

            try {
                $login->begin(session()->driver());
            } finally {
                config(['oidc.client_secret' => FakeOpenIdProvider::CLIENT_SECRET]);
            }
        });

        $this->expectException(UnresolvedExternalIdentity::class);
        $login = $this->app->make(OpenIdConnectLogin::class);
        $login->begin(session()->driver());
        $transaction = $this->transaction();
        $this->provider->idToken = $this->provider->signedIdToken($this->provider->claims($transaction['nonce'], ['sub' => 'not-provisioned']));
        $login->complete(session()->driver(), ['code' => self::CODE, 'state' => $transaction['state']]);
    }

    // ------------------------------------------------------------------
    // Helpers

    private function arrangeTokenResponse(string $case, string $nonce): void
    {
        $claims = $this->provider->claims($nonce);
        $now = time();
        $provider = $this->provider;
        $this->resetProvider();

        match ($case) {
            'signature from another key' => $provider->idToken = $provider->signedIdToken($claims, FakeOpenIdProvider::newKey()),
            'unknown key id' => $provider->idToken = $provider->signedIdToken($claims, FakeOpenIdProvider::newKey(), ['kid' => 'unknown-key']),
            'tampered payload' => $provider->idToken = $this->tampered($provider->signedIdToken($claims), ['sub' => 'someone-else']),
            'unsigned token' => $provider->idToken = FakeOpenIdProvider::unsignedToken(['alg' => 'none'], $claims),
            'HMAC with the client secret' => $provider->idToken = $this->hmacToken($claims),
            'wrong issuer' => $provider->idToken = $provider->signedIdToken(['iss' => 'https://other.example.test'] + $claims),
            'wrong audience' => $provider->idToken = $provider->signedIdToken(['aud' => 'another-client', 'azp' => 'another-client'] + $claims),
            'several audiences without azp' => $provider->idToken = $provider->signedIdToken(array_diff_key(['aud' => [FakeOpenIdProvider::CLIENT_ID, 'another-client']] + $claims, ['azp' => true])),
            'azp of another client' => $provider->idToken = $provider->signedIdToken(['azp' => 'another-client'] + $claims),
            'expired' => $provider->idToken = $provider->signedIdToken(['exp' => $now - 3600, 'iat' => $now - 7200] + $claims),
            'issued in the future' => $provider->idToken = $provider->signedIdToken(['iat' => $now + 3600, 'exp' => $now + 7200] + $claims),
            'not yet valid' => $provider->idToken = $provider->signedIdToken(['nbf' => $now + 3600] + $claims),
            'wrong nonce' => $provider->idToken = $provider->signedIdToken(['nonce' => 'another-nonce'] + $claims),
            'missing nonce' => $provider->idToken = $provider->signedIdToken(array_diff_key($claims, ['nonce' => true])),
            'missing subject' => $provider->idToken = $provider->signedIdToken(array_diff_key($claims, ['sub' => true])),
            'empty subject' => $provider->idToken = $provider->signedIdToken(['sub' => ''] + $claims),
            'non-string subject' => $provider->idToken = $provider->signedIdToken(['sub' => 42] + $claims),
            'no ID Token' => null,
            'token endpoint error' => [$provider->tokenStatus, $provider->tokenResponse] = [400, ['error' => 'invalid_grant']],
            'token endpoint failure' => [$provider->tokenStatus, $provider->tokenResponse] = [500, ['message' => 'unavailable']],
            'provider metadata changed' => $provider->discovery = ['issuer' => 'https://other.example.test'] + $this->discoveryFor(FakeOpenIdProvider::ISSUER),
        };
    }

    /**
     * @param  callable(OpenIdConnectLogin): void  $attempt
     */
    private function assertFailureReason(string $reason, callable $attempt, bool $transactionRemains = false): void
    {
        $this->resetProvider();

        try {
            $attempt($this->app->make(OpenIdConnectLogin::class));
            $this->fail("An OpenID Connect failure [{$reason}] was expected.");
        } catch (OpenIdConnectFailure $failure) {
            $this->assertSame($reason, $failure->reason);
            $this->assertSame('OpenID Connect authentication failed.', $failure->getMessage());
            $this->assertNull($failure->getPrevious());
        } finally {
            $this->resetProvider();
        }

        // Only a response that was not correlated leaves the transaction pending.
        $this->assertSame($transactionRemains, session()->has(OpenIdConnectLogin::TRANSACTION_SESSION_KEY));
    }

    private function resetProvider(): void
    {
        $this->provider->idToken = null;
        $this->provider->tokenResponse = null;
        $this->provider->tokenStatus = 200;
        $this->provider->discovery = null;
    }

    private function provisionedActor(): ActorReference
    {
        $actor = new ActorReference();
        $actor->external_identity_key = ExternalIdentityKey::fromOidc(
            FakeOpenIdProvider::ISSUER,
            '9d6f3c2e-5b1a-4c7d-8e2f-1a2b3c4d5e6f'
        )->value();
        $actor->display_name = 'Provisioned requester';
        $actor->save();

        return $actor;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function sendCallback(array $query): TestResponse
    {
        return $this->get('/auth/callback?'.http_build_query($query));
    }

    /**
     * @return array{state: string, nonce: string, code_verifier: string, created_at: int}
     */
    private function transaction(): array
    {
        $transaction = session(OpenIdConnectLogin::TRANSACTION_SESSION_KEY);
        $this->assertIsArray($transaction, 'A login transaction was expected in the session.');

        return $transaction;
    }

    private function assertAuthenticationFailed(TestResponse $response): void
    {
        $response->assertStatus(401);
        $this->assertSame('Authentication failed.', $response->getContent());
        $this->assertFalse(Auth::check());
    }

    private function sessionDump(): string
    {
        return json_encode(session()->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function discoveryFor(string $issuer): array
    {
        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/protocol/openid-connect/auth',
            'token_endpoint' => $issuer.'/protocol/openid-connect/token',
            'jwks_uri' => $issuer.'/protocol/openid-connect/certs',
            'response_types_supported' => ['code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function tampered(string $token, array $claims): string
    {
        [$header, $payload, $signature] = explode('.', $token);
        $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        return $header.'.'.FakeOpenIdProvider::base64Url(json_encode($claims + $decoded, JSON_UNESCAPED_SLASHES)).'.'.$signature;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function hmacToken(array $claims): string
    {
        $unsigned = rtrim(FakeOpenIdProvider::unsignedToken(['alg' => 'HS256', 'typ' => 'JWT'], $claims), '.');

        return $unsigned.'.'.FakeOpenIdProvider::base64Url(hash_hmac('sha256', $unsigned, FakeOpenIdProvider::CLIENT_SECRET, true));
    }

    /**
     * @return array<string, string>
     */
    private function authoritySnapshot(): array
    {
        return [
            'governance_memberships' => json_encode(DB::table('governance_memberships')->orderBy('actor_reference_id')->get()->all()),
            'resources' => json_encode(DB::table('resources')->orderBy('id')->get()->all()),
            'actor_references' => json_encode(DB::table('actor_references')->orderBy('id')->get()->all()),
        ];
    }
}
