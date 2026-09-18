<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\Authentication;

use App\Authentication\ExternalIdentityKey;
use App\Authentication\OpenIdConnect\OpenIdConnectClient;
use App\Authentication\OpenIdConnect\OpenIdConnectLogin;
use App\Models\ActorReference;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\PostgreSQL\Observability\CapturesLogRecords;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * ADR-012: the operational records of the OpenID Connect login, as the
 * application really writes them. The full table of reasons is exercised by
 * OpenIdConnectLoginTest; here each policy is checked on the actual record,
 * with its execution context, against sentinel values of the flow.
 */
final class OpenIdConnectTelemetryTest extends PostgresTestCase
{
    use CapturesLogRecords;

    private const CLIENT_SECRET_SENTINEL = 'client-secret-SENTINEL-long-enough-for-hmac-256';

    private const CODE_SENTINEL = 'authorization-code-SENTINEL';

    private const PROVIDER_BODY_SENTINEL = 'provider-error-description-SENTINEL';

    private const SUBJECT_SENTINEL = 'subject-SENTINEL-not-provisioned';

    private const PROVISIONED_SUBJECT = '9d6f3c2e-5b1a-4c7d-8e2f-1a2b3c4d5e6f';

    private FakeOpenIdProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config(['oidc' => [
            'issuer' => FakeOpenIdProvider::ISSUER,
            'client_id' => FakeOpenIdProvider::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET_SENTINEL,
            'redirect_uri' => FakeOpenIdProvider::REDIRECT_URI,
        ]]);

        $this->provider = new FakeOpenIdProvider();
        $this->app->instance(OpenIdConnectClient::class, new OpenIdConnectClient($this->provider->httpClient()));

        $this->captureLogRecords();
    }

    public function test_an_operational_failure_is_one_error_carrying_only_its_reason(): void
    {
        $transaction = $this->beginLogin();
        $this->provider->tokenStatus = 400;
        $this->provider->tokenResponse = ['error' => 'invalid_grant', 'error_description' => self::PROVIDER_BODY_SENTINEL];

        $response = $this->sendCallback(['code' => self::CODE_SENTINEL, 'state' => $transaction['state']]);

        $this->assertUnchangedFailureResponse($response);
        $record = $this->soleRecord();
        $this->assertSame(Level::Error, $record->level);
        $this->assertSame('OpenID Connect authentication could not be performed.', $record->message);
        $this->assertSame(['reason' => 'token_exchange'], $record->context);
        $this->assertCallbackExecution($record, $response);
        $this->assertRecordsDoNotContain(
            self::PROVIDER_BODY_SENTINEL,
            'invalid_grant',
            self::CODE_SENTINEL,
            self::CLIENT_SECRET_SENTINEL,
            $transaction['state'],
            $transaction['nonce'],
            $transaction['code_verifier'],
        );
    }

    public function test_a_login_that_cannot_start_is_one_error_of_the_login_execution(): void
    {
        $this->provider->discoveryAvailable = false;

        $response = $this->get('/auth/login');

        $this->assertUnchangedFailureResponse($response);
        $record = $this->soleRecord(Level::Error);
        $this->assertSame(['reason' => 'provider_metadata'], $record->context);
        $this->assertExecutionId($record);
        $this->assertSame('auth.login', $record->extra['operation']);
    }

    public function test_a_state_mismatch_is_one_warning_that_names_no_attack(): void
    {
        $transaction = $this->beginLogin();

        $response = $this->sendCallback(['code' => self::CODE_SENTINEL, 'state' => 'state-SENTINEL-of-another-login']);

        $this->assertUnchangedFailureResponse($response);
        $record = $this->soleRecord();
        $this->assertSame(Level::Warning, $record->level);
        $this->assertSame('OpenID Connect authentication was refused.', $record->message);
        $this->assertSame(['reason' => 'state_mismatch'], $record->context);
        $this->assertCallbackExecution($record, $response);
        $this->assertStringNotContainsStringIgnoringCase('attack', $this->renderedRecords());
        $this->assertRecordsDoNotContain('state-SENTINEL-of-another-login', self::CODE_SENTINEL, $transaction['state']);
    }

    public function test_an_identity_without_actor_reference_is_one_warning_without_the_identity(): void
    {
        $transaction = $this->beginLogin();
        $this->provider->idToken = $this->provider->signedIdToken($this->provider->claims($transaction['nonce'], [
            'sub' => self::SUBJECT_SENTINEL,
        ]));

        $response = $this->sendCallback(['code' => self::CODE_SENTINEL, 'state' => $transaction['state']]);

        $this->assertUnchangedFailureResponse($response);
        $record = $this->soleRecord();
        $this->assertSame(Level::Warning, $record->level);
        $this->assertSame(['reason' => 'unresolved_external_identity'], $record->context);
        $this->assertCallbackExecution($record, $response);
        $this->assertRecordsDoNotContain(
            self::SUBJECT_SENTINEL,
            FakeOpenIdProvider::ISSUER,
            ExternalIdentityKey::fromOidc(FakeOpenIdProvider::ISSUER, self::SUBJECT_SENTINEL)->value(),
            $this->provider->idToken,
            FakeOpenIdProvider::ACCESS_TOKEN,
            FakeOpenIdProvider::REFRESH_TOKEN,
            self::CODE_SENTINEL,
            self::CLIENT_SECRET_SENTINEL,
            $transaction['state'],
            $transaction['nonce'],
            $transaction['code_verifier'],
        );
    }

    public function test_an_id_token_that_fails_validation_is_one_warning_without_the_token(): void
    {
        $transaction = $this->beginLogin();
        $this->provider->idToken = $this->provider->signedIdToken($this->provider->claims('nonce-SENTINEL-of-another-login'));

        $response = $this->sendCallback(['code' => self::CODE_SENTINEL, 'state' => $transaction['state']]);

        $this->assertUnchangedFailureResponse($response);
        $this->assertSame(['reason' => 'id_token'], $this->soleRecord(Level::Warning)->context);
        $this->assertRecordsDoNotContain(
            'nonce-SENTINEL-of-another-login',
            $this->provider->idToken,
            FakeOpenIdProvider::ACCESS_TOKEN,
            FakeOpenIdProvider::REFRESH_TOKEN,
            $transaction['nonce'],
            $transaction['code_verifier'],
        );
    }

    public function test_a_controlled_outcome_of_the_interaction_is_recorded_nowhere(): void
    {
        $this->beginLogin();

        $response = $this->sendCallback(['code' => self::CODE_SENTINEL]);

        $this->assertUnchangedFailureResponse($response);
        $this->assertSame([], $this->records());
    }

    public function test_a_successful_login_and_logout_record_nothing(): void
    {
        $actor = new ActorReference();
        $actor->external_identity_key = ExternalIdentityKey::fromOidc(FakeOpenIdProvider::ISSUER, self::PROVISIONED_SUBJECT)->value();
        $actor->display_name = 'Display-Name-SENTINEL';
        $actor->save();

        $transaction = $this->beginLogin();
        $this->provider->idToken = $this->provider->signedIdToken($this->provider->claims($transaction['nonce']));

        $this->sendCallback(['code' => self::CODE_SENTINEL, 'state' => $transaction['state']])->assertRedirect('/');
        $this->assertTrue(Auth::check());
        $sessionId = session()->getId();

        $this->post('/auth/logout', [], ['Sec-Fetch-Site' => 'same-origin'])->assertRedirect('/');

        $this->assertSame([], $this->records());
        $this->assertRecordsDoNotContain($sessionId, 'Display-Name-SENTINEL', $actor->external_identity_key);
    }

    /**
     * @return array{state: string, nonce: string, code_verifier: string, created_at: int}
     */
    private function beginLogin(): array
    {
        $this->get('/auth/login')->assertRedirect();

        $transaction = session(OpenIdConnectLogin::TRANSACTION_SESSION_KEY);
        $this->assertIsArray($transaction);

        // Starting a login is not an event of its own.
        $this->assertSame([], $this->records());

        return $transaction;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function sendCallback(array $query): TestResponse
    {
        return $this->get('/auth/callback?'.http_build_query($query));
    }

    /**
     * Observability changes nothing the client sees: the same status, body
     * and content type, and no reason and no execution identifier.
     */
    private function assertUnchangedFailureResponse(TestResponse $response): void
    {
        $response->assertStatus(401);
        $this->assertSame('Authentication failed.', $response->getContent());
        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));

        foreach ($this->records() as $record) {
            foreach ([$record->context['reason'] ?? null, $record->extra['execution_id'] ?? null] as $value) {
                if (is_string($value)) {
                    $this->assertStringNotContainsString($value, (string) $response->getContent());
                    $this->assertStringNotContainsString($value, (string) $response->headers);
                }
            }
        }
    }

    private function assertCallbackExecution(LogRecord $record, TestResponse $response): void
    {
        $this->assertExecutionId($record);
        $this->assertSame('auth.callback', $record->extra['operation']);

        // Neither the session nor its cookie ever reaches the record.
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertRecordsDoNotContain((string) $cookie->getValue());
        }

        $this->assertRecordsDoNotContain(session()->getId());
    }
}
