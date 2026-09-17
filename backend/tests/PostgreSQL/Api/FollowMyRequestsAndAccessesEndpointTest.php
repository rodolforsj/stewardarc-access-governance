<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\Api;

use App\AccessGovernance\Actions\ConfirmExternalAccessGrant;
use App\AccessGovernance\Actions\CreateAccessRequest;
use App\AccessGovernance\Actions\DecideAccessRequest;
use App\AccessGovernance\Actions\FollowMyRequestsAndAccesses;
use App\AccessGovernance\Actions\RecordExternalAccessRevocation;
use App\Authentication\ExternalIdentityKey;
use App\Authentication\OpenIdConnect\OpenIdConnectClient;
use App\Authentication\OpenIdConnect\OpenIdConnectLogin;
use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\ActorReference;
use App\Models\Decision;
use App\Models\GrantConfirmation;
use App\Models\GrantedAccess;
use App\Models\Resource;
use App\Models\RevocationConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\PostgreSQL\Authentication\FakeOpenIdProvider;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * UC-005 over HTTP (ADR-010, ADR-011): GET /api/me/requests-and-accesses
 * returns the projection (ADR-008) of the actor in the session, mapped to the
 * explicit, minimal public contract.
 */
final class FollowMyRequestsAndAccessesEndpointTest extends PostgresTestCase
{
    private const ENDPOINT = '/api/me/requests-and-accesses';

    private const UNAUTHENTICATED = '{"message":"Unauthenticated."}';

    /** RFC 3339, in UTC, to the second. */
    private const INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    private const REQUEST_KEYS = [
        'id',
        'access_profile',
        'resource',
        'justification',
        'approval_flow',
        'requested_duration_seconds',
        'current_state',
        'requested_at',
        'granted_access',
        'history',
    ];

    /** Keys that must not appear anywhere in the response. */
    private const FORBIDDEN_KEYS = [
        'projection_reference_at',
        'request_id',
        'fact_id',
        'resource_id',
        'access_profile_id',
        'access_profile_name',
        'resource_name',
        'requester',
        'external_identity_key',
        'requester_actor_reference_id',
        'actor_reference_id',
        'resource_owner_actor_reference_id',
        'access_request_id',
        'grant_confirmation_id',
        'granted_access_id',
        'governance_memberships',
        'classification',
        'is_available',
        'created_at',
        'updated_at',
        'iss',
        'sub',
    ];

    private const OIDC_SUBJECT = '9d6f3c2e-5b1a-4c7d-8e2f-1a2b3c4d5e6f';

    private const PRIVILEGED_DURATION_SECONDS = 3600;

    private ActorReference $owner;

    private ActorReference $governance;

    private Resource $resource;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeActor('Resource Owner');
        $this->governance = $this->makeActor('Governance Member');
        $this->makeGovernanceMembership($this->governance);
        $this->resource = $this->makeResource($this->owner, 'Payroll');

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->level.': '.$event->message;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Authentication

    /**
     * @return array<string, array{callable(self): TestResponse}>
     */
    public static function guestRequests(): array
    {
        return [
            'getJson()' => [static fn (self $test): TestResponse => $test->getJson(self::ENDPOINT)],
            'get() with a browser Accept' => [static fn (self $test): TestResponse => $test->get(self::ENDPOINT)],
            'no Accept header' => [static fn (self $test): TestResponse => $test->getWithoutAcceptHeader(self::ENDPOINT)],
            'Accept */*' => [static fn (self $test): TestResponse => $test->get(self::ENDPOINT, ['Accept' => '*/*'])],
            'Accept text/html' => [static fn (self $test): TestResponse => $test->get(self::ENDPOINT, ['Accept' => 'text/html'])],
            'Accept application/json' => [static fn (self $test): TestResponse => $test->get(self::ENDPOINT, ['Accept' => 'application/json'])],
        ];
    }

    /**
     * Whatever the client accepts, a guest gets the same JSON 401: no redirect,
     * no projection, no read at all, and no failed attempt to reach a login
     * route in the logs.
     *
     * @param  callable(self): TestResponse  $send
     */
    #[DataProvider('guestRequests')]
    public function test_a_guest_gets_a_json_401_and_nothing_runs(callable $send): void
    {
        $this->grantedStandardRequest($this->makeActor('Requester'), 'Never shown to a guest.');
        $queries = $this->recordQueries();

        $response = $send($this);

        $response->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location');
        $this->assertSame(self::UNAUTHENTICATED, $response->getContent());
        $this->assertSame([], $queries());
        $this->assertSame([], $this->logged);
        $this->assertFalse(Auth::check());
    }

    public function test_an_actor_authenticated_through_the_session_gets_their_projection(): void
    {
        $requester = $this->makeActor('Requester');
        [$request] = $this->grantedStandardRequest($requester);
        $this->signInThroughSession($requester);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertSame([$request->id], array_column($response->json('requests'), 'id'));
        $this->assertSame([], $this->logged);
    }

    /** The whole chain: OpenID Connect login, session, Actor Reference, projection. */
    public function test_an_openid_connect_login_opens_the_api_to_the_provisioned_actor(): void
    {
        $provider = new FakeOpenIdProvider();
        config(['oidc' => [
            'issuer' => FakeOpenIdProvider::ISSUER,
            'client_id' => FakeOpenIdProvider::CLIENT_ID,
            'client_secret' => FakeOpenIdProvider::CLIENT_SECRET,
            'redirect_uri' => FakeOpenIdProvider::REDIRECT_URI,
        ]]);
        $this->app->instance(OpenIdConnectClient::class, new OpenIdConnectClient($provider->httpClient()));

        $actor = new ActorReference();
        $actor->external_identity_key = ExternalIdentityKey::fromOidc(FakeOpenIdProvider::ISSUER, self::OIDC_SUBJECT)->value();
        $actor->display_name = 'Provisioned requester';
        $actor->save();
        [$request] = $this->grantedStandardRequest($actor);
        $this->grantedStandardRequest($this->makeActor('Someone else'));

        $this->get('/auth/login')->assertRedirect();
        $transaction = session(OpenIdConnectLogin::TRANSACTION_SESSION_KEY);
        $claims = $provider->claims($transaction['nonce']);
        $this->assertSame(self::OIDC_SUBJECT, $claims['sub']);
        $provider->idToken = $provider->signedIdToken($claims);
        $this->get('/auth/callback?'.http_build_query(['code' => 'test-authorization-code', 'state' => $transaction['state']]))
            ->assertRedirect('/');
        Auth::forgetGuards();

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $this->assertSame([$request->id], array_column($response->json('requests'), 'id'));

        $body = $this->searchableBody($response);
        $headers = (string) $response->headers;

        foreach ([
            $provider->idToken,
            FakeOpenIdProvider::ACCESS_TOKEN,
            FakeOpenIdProvider::REFRESH_TOKEN,
            FakeOpenIdProvider::CLIENT_SECRET,
            $claims['iss'],
            $claims['sub'],
            $actor->external_identity_key,
            session()->getId(),
            session()->token(),
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString($secret, $headers);
        }
    }

    public function test_a_session_whose_actor_does_not_exist_is_unauthenticated(): void
    {
        $removed = $this->makeActor('Removed');
        $this->signInThroughSession($removed);
        DB::table('actor_references')->where('id', $removed->id)->delete();

        $response = $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->assertSame(self::UNAUTHENTICATED, $response->getContent());

        session([Auth::guard()->getName() => (string) Str::uuid7()]);
        Auth::forgetGuards();

        $response = $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->assertSame(self::UNAUTHENTICATED, $response->getContent());
    }

    public function test_a_session_cookie_unknown_to_the_session_store_is_unauthenticated(): void
    {
        $unknownSessionId = Str::random(40);

        $response = $this->withCredentials()
            ->withCookie(config('session.cookie'), $unknownSessionId)
            ->getJson(self::ENDPOINT);

        $response->assertUnauthorized();
        $this->assertSame($unknownSessionId, $response->baseRequest->cookies->get(config('session.cookie')));
    }

    /**
     * @return array<string, array{bool, int}>
     */
    public static function sessionAges(): array
    {
        return [
            'within its lifetime' => [false, 200],
            'past its lifetime' => [true, 401],
        ];
    }

    /** The session is found through its cookie only, and not once it has expired. */
    #[DataProvider('sessionAges')]
    public function test_an_expired_session_is_unauthenticated(bool $expired, int $status): void
    {
        Auth::login($this->makeActor('Requester'), false);
        $sessionId = session()->getId();
        session()->save();

        // The next request can find the actor only in the stored session.
        session()->flush();
        Auth::forgetGuards();
        $this->travel($expired ? (int) config('session.lifetime') + 1 : 1)->minutes();

        $response = $this->withCredentials()
            ->withCookie(config('session.cookie'), $sessionId)
            ->getJson(self::ENDPOINT);

        $response->assertStatus($status);
        $this->assertSame($sessionId, $response->baseRequest->cookies->get(config('session.cookie')));
    }

    public function test_after_logout_the_session_no_longer_reaches_the_api(): void
    {
        $this->signInThroughSession($this->makeActor('Requester'));
        $this->getJson(self::ENDPOINT)->assertOk();

        $this->post('/auth/logout')->assertRedirect('/');
        Auth::forgetGuards();

        $response = $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->assertSame(self::UNAUTHENTICATED, $response->getContent());
    }

    // ------------------------------------------------------------------
    // Scope and identity

    public function test_a_requester_without_requests_gets_an_empty_collection(): void
    {
        $this->grantedStandardRequest($this->makeActor('Someone else'));
        $this->signInThroughSession($this->makeActor('No requests yet'));

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $this->assertSame('{"requests":[]}', $response->getContent());
    }

    /**
     * CA-020: only the Requester's own requests, accesses and history, even
     * when the same actor also holds the responsibility scope of UC-006.
     */
    public function test_the_requester_sees_only_their_own_requests_accesses_and_history(): void
    {
        $requesterA = $this->makeActor('Requester A');
        $requesterB = $this->makeActor('Requester B-only');
        $resourceOfA = $this->makeResource($requesterA, 'B-only resource');
        $this->makeGovernanceMembership($requesterA);

        [$grantedA] = $this->grantedStandardRequest($requesterA);
        $pendingA = $this->createRequest($requesterA, $this->makeProfile($this->resource, 'privileged', name: 'Payroll admin'), self::PRIVILEGED_DURATION_SECONDS);

        // B's requests are decided and confirmed by A, as Resource Owner and as Governance.
        $grantedB = $this->createRequest($requesterB, $this->makeProfile($resourceOfA, name: 'B-only viewer'), justification: 'B-only justification');
        $this->decide($grantedB, $requesterA);
        $grantB = (new ConfirmExternalAccessGrant())->execute($grantedB->id, $requesterA->id);
        $accessB = GrantedAccess::query()->where('grant_confirmation_id', $grantB->id)->firstOrFail();
        (new RecordExternalAccessRevocation())->execute($accessB->id, $requesterA->id);
        $rejectedB = $this->createRequest($requesterB, $this->makeProfile($resourceOfA, name: 'B-only editor'), justification: 'B-only second justification');
        $this->decide($rejectedB, $requesterA, 'rejected', 'B-only rejection');
        $privilegedB = $this->createRequest($requesterB, $this->makeProfile($resourceOfA, 'privileged', name: 'B-only admin'), 7200, 'B-only privileged justification');
        $this->decide($privilegedB, $requesterA);
        $this->decide($privilegedB, $requesterA);
        $expiredB = $this->concludedPrivilegedAccess($requesterB, CarbonImmutable::now('UTC')->startOfSecond()->subDays(2));

        $this->signInThroughSession($requesterA);
        $response = $this->getJson(self::ENDPOINT)->assertOk();
        $requests = $response->json('requests');

        $this->assertEqualsCanonicalizing([$grantedA->id, $pendingA->id], array_column($requests, 'id'));
        $this->assertSame(
            array_column((new FollowMyRequestsAndAccesses())->execute($requesterA->id)['requests'], 'request_id'),
            array_column($requests, 'id')
        );
        $this->assertCount(1, array_filter($requests, static fn (array $request): bool => $request['granted_access'] !== null));

        foreach ($requests as $request) {
            $registration = $this->entriesOf($request, 'request_registered');
            $this->assertCount(1, $registration);
            $this->assertSame($requesterA->id, $registration[0]['actor']['id']);
            $this->assertSame([], $this->entriesOf($request, 'revocation_confirmation'));
            $this->assertSame([], $this->entriesOf($request, 'expiration'));

            foreach ($request['history'] as $entry) {
                $this->assertContains($entry['actor']['id'] ?? null, [$requesterA->id, $this->owner->id]);
            }
        }

        $body = $this->searchableBody($response);

        foreach ([$requesterB->id, $grantedB->id, $rejectedB->id, $privilegedB->id, $expiredB->id, 'B-only'] as $marker) {
            $this->assertStringNotContainsString($marker, $body);
        }
    }

    /** ADR-010: nothing the client sends replaces the actor of the session. */
    public function test_a_client_supplied_actor_changes_nothing(): void
    {
        $requesterA = $this->makeActor('Requester A');
        $requesterB = $this->makeActor('Requester B');
        [$requestA] = $this->grantedStandardRequest($requesterA);
        [$requestB] = $this->grantedStandardRequest($requesterB, 'Only B asked for this.');
        $this->signInThroughSession($requesterA);

        $baseline = $this->getJson(self::ENDPOINT)->assertOk()->json();
        $this->assertSame([$requestA->id], array_column($baseline['requests'], 'id'));

        $impersonations = [
            'query string' => fn (): TestResponse => $this->getJson(self::ENDPOINT.'?'.http_build_query([
                'actor_reference_id' => $requesterB->id,
                'requester_actor_reference_id' => $requesterB->id,
                'actor' => $requesterB->id,
            ])),
            'header' => fn (): TestResponse => $this->getJson(self::ENDPOINT, [
                'X-Actor-Reference-Id' => $requesterB->id,
                'X-Requester-Id' => $requesterB->id,
            ]),
            'JSON body' => fn (): TestResponse => $this->json('GET', self::ENDPOINT, [
                'actor_reference_id' => $requesterB->id,
            ]),
        ];

        foreach ($impersonations as $channel => $send) {
            $response = $send()->assertOk();

            $this->assertSame($baseline, $response->json(), "The {$channel} changed the result.");
            $this->assertStringNotContainsString($requestB->id, $this->searchableBody($response));
            $this->assertStringNotContainsString('Only B asked for this.', $this->searchableBody($response));
        }
    }

    public function test_a_guest_cannot_authenticate_by_naming_an_actor(): void
    {
        $requester = $this->makeActor('Requester');
        $this->grantedStandardRequest($requester);

        $response = $this->getJson(self::ENDPOINT.'?actor_reference_id='.$requester->id, ['X-Actor-Reference-Id' => $requester->id]);

        $response->assertUnauthorized();
        $this->assertSame(self::UNAUTHENTICATED, $response->getContent());
    }

    public function test_the_actor_cannot_be_part_of_the_path(): void
    {
        $requesterB = $this->makeActor('Requester B');
        $this->grantedStandardRequest($requesterB);
        $this->signInThroughSession($this->makeActor('Requester A'));

        $this->getJson(self::ENDPOINT.'/'.$requesterB->id)
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
        $this->getJson('/api/actors/'.$requesterB->id.'/requests-and-accesses')->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Contract

    /** The exact public document: nesting, key order, nullability, instants and justifications as stated. */
    public function test_the_response_is_the_exact_minimal_contract(): void
    {
        $requester = $this->makeActor('Requester');
        $expected = $this->contractFixture($requester);
        $this->signInThroughSession($requester);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertSame($expected, $response->json());
    }

    /**
     * Instants are converted to UTC explicitly, so neither the time zone of the
     * database session nor that of the application shapes them.
     */
    public function test_instants_are_utc_whatever_the_database_and_application_time_zones(): void
    {
        $requester = $this->makeActor('Requester');
        $expected = $this->contractFixture($requester);
        $this->signInThroughSession($requester);

        $databaseTimeZone = DB::selectOne("select current_setting('TimeZone') as time_zone")->time_zone;
        $phpTimeZone = date_default_timezone_get();
        $applicationTimeZone = config('app.timezone');

        try {
            DB::select("select set_config('TimeZone', ?, false)", ['America/Sao_Paulo']);
            date_default_timezone_set('Asia/Tokyo');
            config(['app.timezone' => 'Asia/Tokyo']);

            // The database now hands the instants over with a -03 offset.
            $this->assertStringEndsWith(
                '-03',
                DB::selectOne('select requested_at::text as requested_at from access_requests order by requested_at limit 1')->requested_at
            );

            $this->assertSame($expected, $this->getJson(self::ENDPOINT)->assertOk()->json());
        } finally {
            DB::select("select set_config('TimeZone', ?, false)", [$databaseTimeZone]);
            date_default_timezone_set($phpTimeZone);
            config(['app.timezone' => $applicationTimeZone]);
        }
    }

    public function test_every_level_has_exactly_the_contract_keys(): void
    {
        $requester = $this->richRequesterFixture();
        $this->signInThroughSession($requester);

        $json = $this->getJson(self::ENDPOINT)->assertOk()->json();

        $this->assertSame(['requests'], array_keys($json));
        $this->assertIsList($json['requests']);
        $this->assertCount(8, $json['requests']);

        foreach ($json['requests'] as $request) {
            $this->assertSame(self::REQUEST_KEYS, array_keys($request));
            $this->assertSame(['id', 'name'], array_keys($request['access_profile']));
            $this->assertSame(['name'], array_keys($request['resource']));
            $this->assertMatchesRegularExpression(self::INSTANT, $request['requested_at']);
            $this->assertSame($request['approval_flow'] === 'privileged', $request['requested_duration_seconds'] !== null);
            $this->assertSame($request['current_state'] === 'S4', $request['granted_access'] !== null);

            if ($request['granted_access'] !== null) {
                $this->assertSame(['valid_until_at', 'state'], array_keys($request['granted_access']));
                $this->assertSame($request['approval_flow'] === 'privileged', $request['granted_access']['valid_until_at'] !== null);

                if ($request['granted_access']['valid_until_at'] !== null) {
                    $this->assertMatchesRegularExpression(self::INSTANT, $request['granted_access']['valid_until_at']);
                }
            }

            $this->assertIsList($request['history']);

            foreach ($request['history'] as $entry) {
                $this->assertSame(['kind', 'occurred_at', 'actor', 'decision'], array_keys($entry));
                $this->assertMatchesRegularExpression(self::INSTANT, $entry['occurred_at']);
                $this->assertSame($entry['kind'] === 'expiration', $entry['actor'] === null);

                if ($entry['actor'] !== null) {
                    $this->assertSame(['id', 'display_name'], array_keys($entry['actor']));
                }

                if ($entry['kind'] === 'decision') {
                    $this->assertSame(['stage', 'outcome', 'justification'], array_keys($entry['decision']));
                } else {
                    $this->assertNull($entry['decision']);
                }
            }
        }
    }

    /** RNF-005: no internal identifier, identity key or session artifact leaves the server. */
    public function test_the_response_exposes_no_internal_identifier_or_identity_material(): void
    {
        $requester = $this->richRequesterFixture();
        $this->signInThroughSession($requester);

        $response = $this->getJson(self::ENDPOINT)->assertOk();

        $this->assertNoForbiddenKey($response->json());

        $requestIds = AccessRequest::query()->where('requester_actor_reference_id', $requester->id)->pluck('id')->all();
        $grantIds = GrantConfirmation::query()->whereIn('access_request_id', $requestIds)->pluck('id')->all();
        $accessIds = GrantedAccess::query()->whereIn('grant_confirmation_id', $grantIds)->pluck('id')->all();
        $hidden = array_merge(
            Decision::query()->whereIn('access_request_id', $requestIds)->pluck('id')->all(),
            $grantIds,
            $accessIds,
            RevocationConfirmation::query()->whereIn('granted_access_id', $accessIds)->pluck('id')->all(),
            [$this->resource->id],
            ActorReference::query()->pluck('external_identity_key')->all(),
            [session()->getId(), session()->token()],
        );
        $body = $this->searchableBody($response);

        $this->assertGreaterThan(10, count($hidden));

        foreach ($hidden as $value) {
            $this->assertStringNotContainsString($value, $body);
        }
    }

    /**
     * S1–S5 as persisted and A1–A3 as derived by the projection, with the
     * expiration milestone only when expiration is what ended the access.
     */
    public function test_request_and_access_states_come_from_the_projection(): void
    {
        $requester = $this->makeActor('Requester');
        $now = CarbonImmutable::now('UTC')->startOfSecond();

        $s1 = $this->createRequest($requester, $this->makeProfile($this->resource));
        $s2 = $this->createRequest($requester, $this->makeProfile($this->resource, 'privileged'), 7200);
        $this->decide($s2, $this->owner);
        $s3 = $this->createRequest($requester, $this->makeProfile($this->resource));
        $this->decide($s3, $this->owner);
        [$openEnded] = $this->grantedStandardRequest($requester);
        $withinValidity = $this->createRequest($requester, $this->makeProfile($this->resource, 'privileged'), self::PRIVILEGED_DURATION_SECONDS);
        $this->decide($withinValidity, $this->owner);
        $this->decide($withinValidity, $this->governance);
        $withinValidityGrant = (new ConfirmExternalAccessGrant())->execute($withinValidity->id, $this->owner->id);
        $expired = $this->concludedPrivilegedAccess($requester, $now->subHours(2));
        $revokedEarly = $this->concludedPrivilegedAccess($requester, $now->subHours(2), $now->subMinutes(150));
        $revokedLate = $this->concludedPrivilegedAccess($requester, $now->subHours(2), $now->subHour());
        $revokedAtTheEnd = $this->concludedPrivilegedAccess($requester, $now->subHours(2), $now->subHours(2));
        $s5 = $this->createRequest($requester, $this->makeProfile($this->resource));
        $this->decide($s5, $this->owner, 'rejected', 'Not needed.');

        // request => [current state, access state, expiration entry, revocation entry]
        $expected = [
            $s1->id => ['S1', null, false, false],
            $s2->id => ['S2', null, false, false],
            $s3->id => ['S3', null, false, false],
            $openEnded->id => ['S4', 'A1', false, false],
            $withinValidity->id => ['S4', 'A1', false, false],
            $expired->id => ['S4', 'A2', true, false],
            $revokedEarly->id => ['S4', 'A3', false, true],
            // CA-018, scenario B, and its boundary.
            $revokedLate->id => ['S4', 'A2', true, true],
            $revokedAtTheEnd->id => ['S4', 'A2', true, true],
            $s5->id => ['S5', null, false, false],
        ];

        $this->signInThroughSession($requester);
        $requests = array_column($this->getJson(self::ENDPOINT)->assertOk()->json('requests'), null, 'id');
        $projected = array_column((new FollowMyRequestsAndAccesses())->execute($requester->id)['requests'], null, 'request_id');

        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($requests));

        foreach ($expected as $id => [$state, $accessState, $expires, $revoked]) {
            $request = $requests[$id];

            $this->assertSame($state, $request['current_state']);
            $this->assertSame($projected[$id]['current_state'], $request['current_state']);
            $this->assertSame($accessState, $request['granted_access']['state'] ?? null);
            $this->assertSame($projected[$id]['granted_access']['state'] ?? null, $request['granted_access']['state'] ?? null);
            $this->assertCount($revoked ? 1 : 0, $this->entriesOf($request, 'revocation_confirmation'));

            $expirations = $this->entriesOf($request, 'expiration');
            $this->assertCount($expires ? 1 : 0, $expirations);

            if ($expires) {
                $this->assertSame($request['granted_access']['valid_until_at'], $expirations[0]['occurred_at']);
                $this->assertNull($expirations[0]['actor']);
                $this->assertNull($expirations[0]['decision']);
            }
        }

        // Standard grants have no calculable end; Privileged ends are the persisted ones.
        $this->assertNull($requests[$openEnded->id]['granted_access']['valid_until_at']);
        $this->assertTrue(
            GrantedAccess::query()->where('grant_confirmation_id', $withinValidityGrant->id)->firstOrFail()->valid_until_at
                ->equalTo(CarbonImmutable::parse($requests[$withinValidity->id]['granted_access']['valid_until_at']))
        );
        $this->assertTrue(
            $now->subHours(2)->equalTo(CarbonImmutable::parse($requests[$expired->id]['granted_access']['valid_until_at']))
        );
    }

    /** Requests by requested_at, then id; history in non-decreasing occurred_at. */
    public function test_the_collections_keep_the_projection_order(): void
    {
        $requester = $this->makeActor('Requester');
        $at = CarbonImmutable::now('UTC')->startOfSecond()->subDays(3);

        $later = $this->recordedRequest($requester, $this->makeProfile($this->resource), 'S1', 'Later.', $at->addHour());
        $tiedFirst = $this->recordedRequest($requester, $this->makeProfile($this->resource), 'S1', 'Tied.', $at);
        $tiedSecond = $this->recordedRequest($requester, $this->makeProfile($this->resource), 'S1', 'Tied.', $at);
        $earlier = $this->recordedRequest($requester, $this->makeProfile($this->resource), 'S1', 'Earlier.', $at->subHour());
        $earliest = $this->concludedPrivilegedAccess($requester, $at->subDay(), $at->subDay()->addMinutes(10));

        $tied = [$tiedFirst->id, $tiedSecond->id];
        sort($tied, SORT_STRING);

        $this->signInThroughSession($requester);
        $requests = $this->getJson(self::ENDPOINT)->assertOk()->json('requests');

        $this->assertSame([$earliest->id, $earlier->id, ...$tied, $later->id], array_column($requests, 'id'));

        foreach ($requests as $request) {
            $instants = array_column($request['history'], 'occurred_at');
            $sorted = $instants;
            sort($sorted, SORT_STRING);

            $this->assertSame($sorted, $instants);
        }

        $this->assertCount(6, $requests[0]['history']);
    }

    // ------------------------------------------------------------------
    // Read transaction boundary (ADR-008)

    /**
     * The projection reads in its own read-only snapshot, and nothing is read
     * or written after it: the response is mapped from materialized values.
     */
    public function test_the_response_is_mapped_from_the_materialized_projection_only(): void
    {
        $requester = $this->richRequesterFixture();
        $this->signInThroughSession($requester);
        $before = $this->domainSnapshot();

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
        });

        $response = $this->getJson(self::ENDPOINT);
        $recorded = $queries;

        $response->assertOk();
        $this->assertNotEmpty($response->json('requests'));

        $snapshot = array_search('set transaction isolation level repeatable read, read only', array_column($recorded, 'sql'), true);
        $this->assertIsInt($snapshot, 'The projection did not open its read-only snapshot.');

        foreach ($recorded as $position => $query) {
            $this->assertMatchesRegularExpression('/^(select|set transaction)\b/i', $query['sql']);
            // Before the snapshot, only the session guard reads the actor; from
            // the snapshot on, everything belongs to the projection transaction.
            $this->assertSame($position < $snapshot ? 0 : 1, $query['level'], 'Unexpected transaction level for: '.$query['sql']);
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $this->domainSnapshot());
    }

    // ------------------------------------------------------------------
    // Fixtures

    /**
     * Logs the actor in as the OpenID Connect callback does, then drops the
     * guard, so the request can only find the actor through the session.
     */
    private function signInThroughSession(ActorReference $actor): void
    {
        Auth::login($actor, false);
        Auth::forgetGuards();
    }

    private function createRequest(
        ActorReference $requester,
        AccessProfile $profile,
        ?int $durationSeconds = null,
        string $justification = 'Needed for the quarter close.',
    ): AccessRequest {
        return (new CreateAccessRequest())->execute($requester->id, $profile->id, $justification, $durationSeconds);
    }

    private function decide(
        AccessRequest $request,
        ActorReference $actor,
        string $outcome = 'approved',
        ?string $justification = null,
    ): Decision {
        return (new DecideAccessRequest())->execute($request->id, $actor->id, $outcome, $justification);
    }

    /**
     * A Standard request taken to S4 through the real Actions.
     *
     * @return array{0: AccessRequest, 1: GrantedAccess}
     */
    private function grantedStandardRequest(ActorReference $requester, string $justification = 'Needed for the quarter close.'): array
    {
        $request = $this->createRequest($requester, $this->makeProfile($this->resource), justification: $justification);
        $this->decide($request, $this->owner);
        $confirmation = (new ConfirmExternalAccessGrant())->execute($request->id, $this->owner->id);

        return [$request->fresh(), GrantedAccess::query()->where('grant_confirmation_id', $confirmation->id)->firstOrFail()];
    }

    /** A request recorded directly, with a controlled instant. */
    private function recordedRequest(
        ActorReference $requester,
        AccessProfile $profile,
        string $state,
        string $justification,
        CarbonImmutable $requestedAt,
        ?int $durationSeconds = null,
    ): AccessRequest {
        $request = $this->makeAccessRequest($requester, $profile, $state, $durationSeconds);
        $request->justification = $justification;
        $request->requested_at = $requestedAt;
        $request->save();

        return $request;
    }

    private function recordGrant(AccessRequest $request, CarbonImmutable $recordedAt, ?CarbonImmutable $validUntil): GrantedAccess
    {
        $confirmation = new GrantConfirmation();
        $confirmation->access_request_id = $request->id;
        $confirmation->actor_reference_id = $this->owner->id;
        $confirmation->recorded_at = $recordedAt;
        $confirmation->save();

        $access = new GrantedAccess();
        $access->grant_confirmation_id = $confirmation->id;
        $access->valid_until_at = $validUntil;
        $access->save();

        return $access;
    }

    private function recordRevocation(GrantedAccess $access, CarbonImmutable $recordedAt): void
    {
        $revocation = new RevocationConfirmation();
        $revocation->granted_access_id = $access->id;
        $revocation->actor_reference_id = $this->owner->id;
        $revocation->recorded_at = $recordedAt;
        $revocation->save();
    }

    /**
     * A concluded Privileged request whose access ends at $validUntil and,
     * optionally, was revoked at $revokedAt: facts the real Actions cannot
     * place in the past.
     */
    private function concludedPrivilegedAccess(
        ActorReference $requester,
        CarbonImmutable $validUntil,
        ?CarbonImmutable $revokedAt = null,
    ): AccessRequest {
        $grantedAt = $validUntil->subSeconds(self::PRIVILEGED_DURATION_SECONDS);
        $request = $this->recordedRequest(
            $requester,
            $this->makeProfile($this->resource, 'privileged'),
            'S4',
            'Needed for the quarter close.',
            $grantedAt->subHours(2),
            self::PRIVILEGED_DURATION_SECONDS,
        );
        $this->makeDecision($request, $this->owner, 'resource_owner', decidedAt: $grantedAt->subMinutes(90));
        $this->makeDecision($request, $this->governance, 'governance', decidedAt: $grantedAt->subMinutes(60));
        $access = $this->recordGrant($request, $grantedAt, $validUntil);

        if ($revokedAt !== null) {
            $this->recordRevocation($access, $revokedAt);
        }

        return $request;
    }

    /** A requester whose requests cover every request state, access state and history kind. */
    private function richRequesterFixture(): ActorReference
    {
        $requester = $this->makeActor('Requester');
        $now = CarbonImmutable::now('UTC')->startOfSecond();

        [, $revokedAccess] = $this->grantedStandardRequest($requester);
        (new RecordExternalAccessRevocation())->execute($revokedAccess->id, $this->owner->id);
        $this->grantedStandardRequest($requester);
        $rejected = $this->createRequest($requester, $this->makeProfile($this->resource));
        $this->decide($rejected, $this->owner, 'rejected', 'Not needed.');
        $awaitingGovernance = $this->createRequest($requester, $this->makeProfile($this->resource, 'privileged'), 7200);
        $this->decide($awaitingGovernance, $this->owner, justification: 'Fine by the owner.');
        $this->createRequest($requester, $this->makeProfile($this->resource));
        $this->concludedPrivilegedAccess($requester, $now->subHours(3));
        $this->concludedPrivilegedAccess($requester, $now->subHours(3), $now->subHours(2));
        $this->concludedPrivilegedAccess($requester, $now->subHours(3), $now->subHours(4)->addMinutes(30));

        $this->grantedStandardRequest($this->makeActor('Other requester'));

        return $requester;
    }

    /**
     * Three requests with fixed instants in the past, and the exact response
     * expected for them: a Privileged access that expired and was then revoked
     * (CA-018, scenario B), a rejected Standard request and a pending one.
     *
     * @return array<string, mixed>
     */
    private function contractFixture(ActorReference $requester): array
    {
        $at = static fn (string $instant): CarbonImmutable => CarbonImmutable::parse($instant, 'UTC');

        $adminProfile = $this->makeProfile($this->resource, 'privileged', name: 'Payroll admin');
        $admin = $this->recordedRequest($requester, $adminProfile, 'S4', 'Needed for the quarter close.', $at('2026-03-02 09:00:00'), 3600);
        $this->makeDecision($admin, $this->owner, 'resource_owner', justification: 'Covered by the quarter-close plan.', decidedAt: $at('2026-03-02 09:30:00'));
        $this->makeDecision($admin, $this->governance, 'governance', decidedAt: $at('2026-03-02 10:00:00'));
        $adminAccess = $this->recordGrant($admin, $at('2026-03-02 11:00:00'), $at('2026-03-02 12:00:00'));
        $this->recordRevocation($adminAccess, $at('2026-03-02 12:30:00'));

        $viewerProfile = $this->makeProfile($this->resource, name: 'Viewer');
        $viewer = $this->recordedRequest($requester, $viewerProfile, 'S5', "  Read-only access\nfor the audit.  ", $at('2026-03-03 08:00:00'));
        $this->makeDecision($viewer, $this->owner, 'resource_owner', 'rejected', 'Not part of the role.', $at('2026-03-03 09:15:00'));

        $readerProfile = $this->makeProfile($this->resource, name: 'Reader');
        $reader = $this->recordedRequest($requester, $readerProfile, 'S1', 'Daily reports.', $at('2026-03-04 10:00:00'));

        $requesterActor = ['id' => $requester->id, 'display_name' => 'Requester'];
        $ownerActor = ['id' => $this->owner->id, 'display_name' => 'Resource Owner'];
        $governanceActor = ['id' => $this->governance->id, 'display_name' => 'Governance Member'];

        return [
            'requests' => [
                [
                    'id' => $admin->id,
                    'access_profile' => ['id' => $adminProfile->id, 'name' => 'Payroll admin'],
                    'resource' => ['name' => 'Payroll'],
                    'justification' => 'Needed for the quarter close.',
                    'approval_flow' => 'privileged',
                    'requested_duration_seconds' => 3600,
                    'current_state' => 'S4',
                    'requested_at' => '2026-03-02T09:00:00Z',
                    'granted_access' => ['valid_until_at' => '2026-03-02T12:00:00Z', 'state' => 'A2'],
                    'history' => [
                        ['kind' => 'request_registered', 'occurred_at' => '2026-03-02T09:00:00Z', 'actor' => $requesterActor, 'decision' => null],
                        ['kind' => 'decision', 'occurred_at' => '2026-03-02T09:30:00Z', 'actor' => $ownerActor, 'decision' => [
                            'stage' => 'resource_owner',
                            'outcome' => 'approved',
                            'justification' => 'Covered by the quarter-close plan.',
                        ]],
                        ['kind' => 'decision', 'occurred_at' => '2026-03-02T10:00:00Z', 'actor' => $governanceActor, 'decision' => [
                            'stage' => 'governance',
                            'outcome' => 'approved',
                            'justification' => null,
                        ]],
                        ['kind' => 'grant_confirmation', 'occurred_at' => '2026-03-02T11:00:00Z', 'actor' => $ownerActor, 'decision' => null],
                        ['kind' => 'expiration', 'occurred_at' => '2026-03-02T12:00:00Z', 'actor' => null, 'decision' => null],
                        ['kind' => 'revocation_confirmation', 'occurred_at' => '2026-03-02T12:30:00Z', 'actor' => $ownerActor, 'decision' => null],
                    ],
                ],
                [
                    'id' => $viewer->id,
                    'access_profile' => ['id' => $viewerProfile->id, 'name' => 'Viewer'],
                    'resource' => ['name' => 'Payroll'],
                    'justification' => "  Read-only access\nfor the audit.  ",
                    'approval_flow' => 'standard',
                    'requested_duration_seconds' => null,
                    'current_state' => 'S5',
                    'requested_at' => '2026-03-03T08:00:00Z',
                    'granted_access' => null,
                    'history' => [
                        ['kind' => 'request_registered', 'occurred_at' => '2026-03-03T08:00:00Z', 'actor' => $requesterActor, 'decision' => null],
                        ['kind' => 'decision', 'occurred_at' => '2026-03-03T09:15:00Z', 'actor' => $ownerActor, 'decision' => [
                            'stage' => 'resource_owner',
                            'outcome' => 'rejected',
                            'justification' => 'Not part of the role.',
                        ]],
                    ],
                ],
                [
                    'id' => $reader->id,
                    'access_profile' => ['id' => $readerProfile->id, 'name' => 'Reader'],
                    'resource' => ['name' => 'Payroll'],
                    'justification' => 'Daily reports.',
                    'approval_flow' => 'standard',
                    'requested_duration_seconds' => null,
                    'current_state' => 'S1',
                    'requested_at' => '2026-03-04T10:00:00Z',
                    'granted_access' => null,
                    'history' => [
                        ['kind' => 'request_registered', 'occurred_at' => '2026-03-04T10:00:00Z', 'actor' => $requesterActor, 'decision' => null],
                    ],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers

    /**
     * The test client always sends an Accept header, so this request is built
     * and handled directly, without one.
     */
    private function getWithoutAcceptHeader(string $uri): TestResponse
    {
        $request = $this->createTestRequest(SymfonyRequest::create($this->prepareUrlForRequest($uri), 'GET'));
        $request->headers->remove('Accept');
        $request->server->remove('HTTP_ACCEPT');
        $this->assertFalse($request->headers->has('Accept'));

        $kernel = $this->app->make(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $this->createTestResponse($response, $request);
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
     * The raw body and its unescaped form, so that a value is found however
     * the JSON encoder escaped it.
     */
    private function searchableBody(TestResponse $response): string
    {
        return $response->getContent()."\n".json_encode($response->json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return list<array<string, mixed>>
     */
    private function entriesOf(array $request, string $kind): array
    {
        return array_values(array_filter(
            $request['history'],
            static fn (array $entry): bool => $entry['kind'] === $kind
        ));
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function assertNoForbiddenKey(array $value): void
    {
        foreach ($value as $key => $item) {
            $this->assertNotContains($key, self::FORBIDDEN_KEYS, "Forbidden key [{$key}] in the response.");

            if (is_array($item)) {
                $this->assertNoForbiddenKey($item);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function domainSnapshot(): array
    {
        $snapshot = [];

        foreach (self::DOMAIN_TABLES as $table) {
            $snapshot[$table] = json_encode(DB::table($table)->orderByRaw('1')->get()->all(), JSON_THROW_ON_ERROR);
        }

        return $snapshot;
    }
}
