<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Actions\ConfirmExternalAccessGrant;
use App\AccessGovernance\Actions\CreateAccessRequest;
use App\AccessGovernance\Actions\DecideAccessRequest;
use App\AccessGovernance\Actions\FollowMyRequestsAndAccesses;
use App\AccessGovernance\Actions\RecordExternalAccessRevocation;
use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\ActorReference;
use App\Models\Decision;
use App\Models\GrantConfirmation;
use App\Models\GrantedAccess;
use App\Models\Resource;
use App\Models\RevocationConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * UC-005 — the Requester follows their own requests (RF-009), Granted Accesses
 * (RF-010) and Functional History (RF-011), read under ADR-008.
 */
final class FollowMyRequestsAndAccessesTest extends PostgresTestCase
{
    private const HISTORY_KINDS = [
        'request_registered',
        'decision',
        'grant_confirmation',
        'expiration',
        'revocation_confirmation',
    ];

    private const PRIVILEGED_DURATION_SECONDS = 3600;

    private ActorReference $owner;

    private ActorReference $governance;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeActor('Resource Owner');
        $this->governance = $this->makeActor('Governance Member');
        $this->makeGovernanceMembership($this->governance);
        $this->resource = $this->makeResource($this->owner, 'Payroll');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_actor_without_requests_gets_an_empty_projection(): void
    {
        $this->grantedStandardRequest($this->makeActor('Someone else'));
        $actor = $this->makeActor('No requests');

        foreach ([$actor->id, (string) Str::uuid7()] as $actorId) {
            $projection = (new FollowMyRequestsAndAccesses())->execute($actorId);

            $this->assertInstanceOf(CarbonImmutable::class, $projection['projection_reference_at']);
            $this->assertSame([], $projection['requests']);
        }
    }

    /** CA-020: the Requester sees only their own requests, accesses and history. */
    public function test_only_the_requesters_own_requests_accesses_and_histories_are_returned(): void
    {
        $requesterA = $this->makeActor('Requester A');
        $requesterB = $this->makeActor('Requester B');

        [$grantedA] = $this->grantedStandardRequest($requesterA);
        $pendingA = $this->createRequest($requesterA, $this->makeProfile($this->resource, 'privileged'), self::PRIVILEGED_DURATION_SECONDS);
        $this->decide($pendingA, $this->owner);

        [$grantedB, $accessB] = $this->grantedStandardRequest($requesterB);
        (new RecordExternalAccessRevocation())->execute($accessB->id, $this->owner->id);
        $rejectedB = $this->createRequest($requesterB, $this->makeProfile($this->resource));
        $this->decide($rejectedB, $this->owner, 'rejected', 'Not needed.');

        $projection = $this->project($requesterA);

        $this->assertEqualsCanonicalizing(
            [$grantedA->id, $pendingA->id],
            array_column($projection['requests'], 'request_id')
        );
        $this->assertEqualsCanonicalizing(
            $this->grantedAccessIdsOf($requesterA),
            array_column(array_filter(array_column($projection['requests'], 'granted_access')), 'id')
        );

        $factsOfA = $this->factIdsOf($requesterA);
        $factsOfB = $this->factIdsOf($requesterB);
        $projectedFacts = [];

        foreach ($projection['requests'] as $request) {
            $registration = $this->onlyEntry($request, 'request_registered');
            $this->assertSame($requesterA->id, $registration['actor']['id']);

            foreach ($request['history'] as $entry) {
                $projectedFacts[] = $entry['fact_id'];
            }
        }

        $this->assertEqualsCanonicalizing($factsOfA, $projectedFacts);
        $this->assertSame([], array_values(array_intersect($factsOfB, $projectedFacts)));
        $this->assertNotContains($accessB->id, array_column(array_filter(array_column($projection['requests'], 'granted_access')), 'id'));
    }

    /** RF-009: every request is listed with its persisted current state. */
    public function test_each_request_exposes_its_persisted_current_state(): void
    {
        $requester = $this->makeActor('Requester');
        $privilegedProfile = $this->makeProfile($this->resource, 'privileged', name: 'Payroll admin');

        $s1 = $this->createRequest($requester, $this->makeProfile($this->resource, name: 'Viewer'));
        $s2 = $this->createRequest($requester, $privilegedProfile, 7200);
        $this->decide($s2, $this->owner);
        $s3 = $this->createRequest($requester, $this->makeProfile($this->resource, name: 'Editor'));
        $this->decide($s3, $this->owner);
        [$s4] = $this->grantedStandardRequest($requester);
        $s5 = $this->createRequest($requester, $this->makeProfile($this->resource, name: 'Approver'));
        $this->decide($s5, $this->owner, 'rejected', 'Not part of the role.');

        $projection = $this->project($requester);

        $this->assertCount(5, $projection['requests']);

        $expected = [$s1->id => 'S1', $s2->id => 'S2', $s3->id => 'S3', $s4->id => 'S4', $s5->id => 'S5'];

        foreach ($expected as $requestId => $state) {
            $request = $this->requestIn($projection, $requestId);
            $persisted = AccessRequest::query()->findOrFail($requestId);

            $this->assertSame($state, $request['current_state']);
            $this->assertSame($persisted->current_state, $request['current_state']);
            $this->assertSame($persisted->access_profile_id, $request['access_profile_id']);
            $this->assertSame($persisted->justification, $request['justification']);
            $this->assertSame($persisted->approval_flow, $request['approval_flow']);
            $this->assertSame($persisted->requested_duration_seconds, $request['requested_duration_seconds']);
            $this->assertTrue($persisted->requested_at->equalTo($request['requested_at']));
            $this->assertSame($this->resource->id, $request['resource_id']);
            $this->assertSame('Payroll', $request['resource_name']);
            $this->assertSame($state === 'S4', $request['granted_access'] !== null);
        }

        $this->assertSame('Payroll admin', $this->requestIn($projection, $s2->id)['access_profile_name']);
        $this->assertSame('privileged', $this->requestIn($projection, $s2->id)['approval_flow']);
        $this->assertSame(7200, $this->requestIn($projection, $s2->id)['requested_duration_seconds']);
        $this->assertNull($this->requestIn($projection, $s1->id)['requested_duration_seconds']);
    }

    /** CA-013 and CA-021: a rejection is projected with its justification as persisted. */
    public function test_a_rejected_request_projects_its_registration_and_the_justified_rejection(): void
    {
        $requester = $this->makeActor('Requester');
        $request = $this->createRequest($requester, $this->makeProfile($this->resource));
        $justification = "  Not required for the current role.\nAsk again next quarter.  ";
        $decision = $this->decide($request, $this->owner, 'rejected', $justification);

        $projected = $this->requestIn($this->project($requester), $request->id);

        $this->assertSame('S5', $projected['current_state']);
        $this->assertNull($projected['granted_access']);
        $this->assertEqualsCanonicalizing(['request_registered', 'decision'], $this->kinds($projected));

        $registration = $this->onlyEntry($projected, 'request_registered');
        $this->assertSame($request->id, $registration['fact_id']);
        $this->assertTrue($request->fresh()->requested_at->equalTo($registration['occurred_at']));
        $this->assertSame(['id' => $requester->id, 'display_name' => 'Requester'], $registration['actor']);
        $this->assertNull($registration['stage']);
        $this->assertNull($registration['outcome']);
        $this->assertNull($registration['justification']);

        $rejection = $this->onlyEntry($projected, 'decision');
        $this->assertSame($decision->id, $rejection['fact_id']);
        $this->assertSame('resource_owner', $rejection['stage']);
        $this->assertSame('rejected', $rejection['outcome']);
        $this->assertSame($justification, $rejection['justification']);
        $this->assertSame(['id' => $this->owner->id, 'display_name' => 'Resource Owner'], $rejection['actor']);
        $this->assertTrue($decision->fresh()->decided_at->equalTo($rejection['occurred_at']));
    }

    public function test_a_governance_rejection_is_projected_after_the_owner_approval(): void
    {
        $requester = $this->makeActor('Requester');
        $request = $this->createRequest($requester, $this->makeProfile($this->resource, 'privileged'), self::PRIVILEGED_DURATION_SECONDS);
        $approval = $this->decide($request, $this->owner);
        $rejection = $this->decide($request, $this->governance, 'rejected', 'Privileged access is not justified.');

        $projected = $this->requestIn($this->project($requester), $request->id);

        $this->assertSame('S5', $projected['current_state']);
        $decisions = $this->entriesOf($projected, 'decision');
        $this->assertCount(2, $decisions);

        $byId = array_column($decisions, null, 'fact_id');
        $this->assertSame(['resource_owner', 'approved', null, $this->owner->id], [
            $byId[$approval->id]['stage'], $byId[$approval->id]['outcome'], $byId[$approval->id]['justification'], $byId[$approval->id]['actor']['id'],
        ]);
        $this->assertSame(['governance', 'rejected', 'Privileged access is not justified.', $this->governance->id], [
            $byId[$rejection->id]['stage'], $byId[$rejection->id]['outcome'], $byId[$rejection->id]['justification'], $byId[$rejection->id]['actor']['id'],
        ]);
        $this->assertSame([], $this->entriesOf($projected, 'grant_confirmation'));
    }

    /** CA-016: Standard grant, open-ended validity, no redundant access entry. */
    public function test_a_standard_granted_access_is_active_and_projected_once_in_history(): void
    {
        $requester = $this->makeActor('Requester');
        [$request, $access, $confirmation] = $this->grantedStandardRequest($requester);

        $projection = $this->project($requester);
        $projected = $this->requestIn($projection, $request->id);

        $this->assertSame('S4', $projected['current_state']);
        $this->assertSame($access->id, $projected['granted_access']['id']);
        $this->assertNull($projected['granted_access']['valid_until_at']);
        $this->assertSame('A1', $projected['granted_access']['state']);
        $this->assertEqualsCanonicalizing(
            ['request_registered', 'decision', 'grant_confirmation'],
            $this->kinds($projected)
        );

        $grant = $this->onlyEntry($projected, 'grant_confirmation');
        $this->assertSame($confirmation->id, $grant['fact_id']);
        $this->assertTrue($confirmation->recorded_at->equalTo($grant['occurred_at']));
        $this->assertSame(['id' => $this->owner->id, 'display_name' => 'Resource Owner'], $grant['actor']);
        $this->assertNull($grant['stage']);
        $this->assertNull($grant['outcome']);
        $this->assertNull($grant['justification']);
    }

    /** RN07: the projection reads the effective validity end as persisted by the grant. */
    public function test_a_privileged_granted_access_within_its_validity_is_active(): void
    {
        $requester = $this->makeActor('Requester');
        $request = $this->createRequest($requester, $this->makeProfile($this->resource, 'privileged'), self::PRIVILEGED_DURATION_SECONDS);
        $ownerApproval = $this->decide($request, $this->owner);
        $governanceApproval = $this->decide($request, $this->governance);
        $confirmation = (new ConfirmExternalAccessGrant())->execute($request->id, $this->owner->id);
        $access = GrantedAccess::query()->where('grant_confirmation_id', $confirmation->id)->firstOrFail();

        $projection = $this->project($requester);
        $projected = $this->requestIn($projection, $request->id);

        $this->assertSame('S4', $projected['current_state']);
        $this->assertSame('A1', $projected['granted_access']['state']);
        $this->assertTrue($access->valid_until_at->equalTo($projected['granted_access']['valid_until_at']));
        $this->assertTrue(
            $confirmation->recorded_at->addSeconds(self::PRIVILEGED_DURATION_SECONDS)->equalTo($projected['granted_access']['valid_until_at'])
        );
        $this->assertTrue($projection['projection_reference_at']->lessThan($projected['granted_access']['valid_until_at']));

        $this->assertEqualsCanonicalizing(
            ['request_registered', 'decision', 'decision', 'grant_confirmation'],
            $this->kinds($projected)
        );

        $decisions = array_column($this->entriesOf($projected, 'decision'), null, 'fact_id');
        $this->assertSame('resource_owner', $decisions[$ownerApproval->id]['stage']);
        $this->assertSame($this->owner->id, $decisions[$ownerApproval->id]['actor']['id']);
        $this->assertSame('governance', $decisions[$governanceApproval->id]['stage']);
        $this->assertSame(
            ['id' => $this->governance->id, 'display_name' => 'Governance Member'],
            $decisions[$governanceApproval->id]['actor']
        );
    }

    /** CA-017 / RF-008: expiration ends the access in governance and is a derived milestone. */
    public function test_an_expired_privileged_access_is_a2_with_a_derived_expiration_milestone(): void
    {
        $requester = $this->makeActor('Requester');
        $validUntil = CarbonImmutable::now()->subHours(2)->startOfSecond();
        $fixture = $this->concludedPrivilegedAccess($requester, $validUntil);

        $projection = $this->project($requester);
        $projected = $this->requestIn($projection, $fixture['request']->id);

        $this->assertTrue($projection['projection_reference_at']->greaterThanOrEqualTo($validUntil));
        $this->assertSame('S4', $projected['current_state']);
        $this->assertSame('A2', $projected['granted_access']['state']);
        $this->assertEqualsCanonicalizing(
            ['request_registered', 'decision', 'decision', 'grant_confirmation', 'expiration'],
            $this->kinds($projected)
        );
        $this->assertExpirationMilestone($projected, $validUntil);
        $this->assertSame(0, RevocationConfirmation::query()->count());
    }

    /** An access revoked while active stays A3; time passing adds no expiration. */
    public function test_an_access_revoked_before_its_validity_end_stays_a3_without_expiration(): void
    {
        $requester = $this->makeActor('Requester');
        $validUntil = CarbonImmutable::now()->subHours(2)->startOfSecond();
        $revokedAt = $validUntil->subMinutes(30);
        $fixture = $this->concludedPrivilegedAccess($requester, $validUntil, $revokedAt);

        $projection = $this->project($requester);
        $projected = $this->requestIn($projection, $fixture['request']->id);

        $this->assertTrue($projection['projection_reference_at']->greaterThan($validUntil));
        $this->assertSame('A3', $projected['granted_access']['state']);
        $this->assertSame([], $this->entriesOf($projected, 'expiration'));
        $this->assertRevocationEntry($projected, $fixture['revocation'], $revokedAt);
    }

    /** CA-018 scenario B: a revocation after the expiration keeps A2 and both history entries. */
    public function test_a_revocation_recorded_after_the_expiration_keeps_a2_with_both_entries(): void
    {
        $requester = $this->makeActor('Requester');
        $validUntil = CarbonImmutable::now()->subHours(2)->startOfSecond();
        $revokedAt = $validUntil->addMinutes(30);
        $fixture = $this->concludedPrivilegedAccess($requester, $validUntil, $revokedAt);

        $projected = $this->requestIn($this->project($requester), $fixture['request']->id);

        $this->assertSame('A2', $projected['granted_access']['state']);
        $this->assertExpirationMilestone($projected, $validUntil);
        $this->assertRevocationEntry($projected, $fixture['revocation'], $revokedAt);
    }

    /** Boundary: a revocation recorded exactly at the validity end does not produce A3. */
    public function test_a_revocation_recorded_exactly_at_the_validity_end_is_a2(): void
    {
        $requester = $this->makeActor('Requester');
        $validUntil = CarbonImmutable::now()->subHours(2)->startOfSecond();
        $fixture = $this->concludedPrivilegedAccess($requester, $validUntil, $validUntil);

        $projected = $this->requestIn($this->project($requester), $fixture['request']->id);

        $this->assertTrue($fixture['revocation']->fresh()->recorded_at->equalTo($fixture['access']->fresh()->valid_until_at));
        $this->assertSame('A2', $projected['granted_access']['state']);
        $this->assertExpirationMilestone($projected, $validUntil);
        $this->assertRevocationEntry($projected, $fixture['revocation'], $validUntil);
    }

    /**
     * One reference instant for the whole projection, including historical
     * requests for the same profile. The PHP clock is moved far ahead to show
     * that it plays no part in the derivation.
     */
    public function test_every_access_is_derived_against_the_single_projection_reference_instant(): void
    {
        $requester = $this->makeActor('Requester');
        $now = CarbonImmutable::now()->startOfSecond();
        $sameProfile = $this->makeProfile($this->resource, name: 'Reused profile');

        [$firstRequest, $firstAccess] = $this->grantedStandardRequest($requester, $sameProfile);
        (new RecordExternalAccessRevocation())->execute($firstAccess->id, $this->owner->id);
        [$secondRequest, $secondAccess] = $this->grantedStandardRequest($requester, $sameProfile);

        $activePrivileged = $this->createRequest($requester, $this->makeProfile($this->resource, 'privileged'), self::PRIVILEGED_DURATION_SECONDS);
        $this->decide($activePrivileged, $this->owner);
        $this->decide($activePrivileged, $this->governance);
        (new ConfirmExternalAccessGrant())->execute($activePrivileged->id, $this->owner->id);

        $expired = $this->concludedPrivilegedAccess($requester, $now->subHours(3));
        $early = $this->concludedPrivilegedAccess($requester, $now->subHours(3), $now->subHours(4));
        $late = $this->concludedPrivilegedAccess($requester, $now->subHours(3), $now->subHours(2));
        $boundary = $this->concludedPrivilegedAccess($requester, $now->subHours(3), $now->subHours(3));

        Carbon::setTestNow($now->addYear());

        try {
            $projection = $this->project($requester);
        } finally {
            Carbon::setTestNow();
        }

        $referenceAt = $projection['projection_reference_at'];
        $this->assertTrue($referenceAt->lessThan($now->addHour()), 'The reference must come from PostgreSQL, not the PHP clock.');

        $states = [];

        foreach ($projection['requests'] as $request) {
            if ($request['granted_access'] === null) {
                continue;
            }

            $access = GrantedAccess::query()->findOrFail($request['granted_access']['id']);
            $this->assertSame(
                $this->derivedGrantedAccessState($access, $referenceAt),
                $request['granted_access']['state'],
                'Access '.$access->id.' must be derived against the projection reference instant.'
            );
            $states[$request['request_id']] = $request['granted_access']['state'];
        }

        $expectedStates = [
            $firstRequest->id => 'A3',
            $secondRequest->id => 'A1',
            $activePrivileged->id => 'A1',
            $expired['request']->id => 'A2',
            $early['request']->id => 'A3',
            $late['request']->id => 'A2',
            $boundary['request']->id => 'A2',
        ];
        ksort($expectedStates);
        ksort($states);
        $this->assertSame($expectedStates, $states);

        // Historical requests for the same profile are not collapsed.
        $this->assertSame($sameProfile->id, $this->requestIn($projection, $firstRequest->id)['access_profile_id']);
        $this->assertSame($sameProfile->id, $this->requestIn($projection, $secondRequest->id)['access_profile_id']);
        $this->assertNotSame($firstAccess->id, $secondAccess->id);
    }

    /** The historical flow snapshot is kept; catalog names are current reference data. */
    public function test_the_request_flow_snapshot_is_projected_even_if_the_catalog_changed(): void
    {
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->resource, 'standard', name: 'Old name');
        [$request] = $this->grantedStandardRequest($requester, $profile);

        DB::table('access_profiles')->where('id', $profile->id)->update(['classification' => 'privileged', 'name' => 'New name']);

        $projected = $this->requestIn($this->project($requester), $request->id);

        $this->assertSame('standard', $projected['approval_flow']);
        $this->assertSame('New name', $projected['access_profile_name']);
        $this->assertSame('A1', $projected['granted_access']['state']);
    }

    /** RNF-004 and RNF-005: every authored entry carries only the actor's id and display name. */
    public function test_history_actors_are_the_recording_actors_and_expose_only_id_and_display_name(): void
    {
        $requester = $this->makeActor('Requester');
        $request = $this->createRequest($requester, $this->makeProfile($this->resource, 'privileged'), self::PRIVILEGED_DURATION_SECONDS);
        $this->decide($request, $this->owner);
        $this->decide($request, $this->governance);
        $confirmation = (new ConfirmExternalAccessGrant())->execute($request->id, $this->owner->id);
        $access = GrantedAccess::query()->where('grant_confirmation_id', $confirmation->id)->firstOrFail();
        (new RecordExternalAccessRevocation())->execute($access->id, $this->owner->id);
        $this->concludedPrivilegedAccess($requester, CarbonImmutable::now()->subHour()->startOfSecond());

        $projection = $this->project($requester);
        $expected = [
            'request_registered' => $requester,
            'grant_confirmation' => $this->owner,
            'revocation_confirmation' => $this->owner,
        ];

        foreach ($projection['requests'] as $projected) {
            foreach ($projected['history'] as $entry) {
                $this->assertContains($entry['kind'], self::HISTORY_KINDS);

                if ($entry['kind'] === 'expiration') {
                    $this->assertNull($entry['actor']);

                    continue;
                }

                $this->assertSame(['id', 'display_name'], array_keys($entry['actor']));

                $actor = $entry['kind'] === 'decision'
                    ? Decision::query()->findOrFail($entry['fact_id'])->actor_reference_id
                    : $expected[$entry['kind']]->id;
                $this->assertSame($actor, $entry['actor']['id']);
                $this->assertSame(ActorReference::query()->findOrFail($actor)->display_name, $entry['actor']['display_name']);
            }
        }

        $this->assertNoKeyAnywhere($projection, 'external_identity_key');

        $identityKeys = ActorReference::query()->pluck('external_identity_key')->all();
        array_walk_recursive($projection, function (mixed $value) use ($identityKeys): void {
            $this->assertNotContains($value, $identityKeys, 'An external identity key leaked into the projection.');
        });
    }

    /** ADR-008: the result is fully materialized and reading it runs no query. */
    public function test_the_projection_is_fully_materialized_and_triggers_no_query_after_it_returns(): void
    {
        $requester = $this->richRequesterFixture();

        $projection = $this->project($requester);

        $this->assertNotEmpty($projection['requests']);
        $this->assertMaterialized($projection);
        $this->assertSame(0, DB::transactionLevel());

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $encoded = json_encode($projection, JSON_THROW_ON_ERROR);
        array_walk_recursive($projection, static function (mixed $value): void {
            if ($value instanceof CarbonImmutable) {
                $value->toIso8601String();
            }
        });

        $this->assertNotSame('', $encoded);
        $this->assertSame(0, $queries);
    }

    /** ADR-008: no read locks, every query inside the read transaction, no N+1. */
    public function test_the_projection_takes_no_lock_and_reads_only_inside_its_transaction(): void
    {
        $requester = $this->richRequesterFixture();

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
        });

        $projection = $this->project($requester);

        $this->assertGreaterThanOrEqual(5, count($projection['requests']));
        $this->assertSame('set transaction isolation level repeatable read, read only', $queries[0]['sql']);
        $this->assertSame('select transaction_timestamp() as projection_reference_at', $queries[1]['sql']);
        // Eager loading keeps the number of queries independent of the number of requests.
        $this->assertLessThanOrEqual(13, count($queries));

        foreach ($queries as $query) {
            $this->assertSame(1, $query['level'], 'Query outside the read transaction: '.$query['sql']);
            $this->assertDoesNotMatchRegularExpression('/\bfor\s+(no\s+key\s+)?(update|share|key\s+share)\b/i', $query['sql']);
            $this->assertStringNotContainsStringIgnoringCase('pg_advisory', $query['sql']);
            $this->assertMatchesRegularExpression('/^(select|set transaction)\b/i', $query['sql']);
        }
    }

    /** RNF-003: following requests changes no functional fact. */
    public function test_the_projection_writes_nothing(): void
    {
        $requester = $this->richRequesterFixture();
        $before = $this->domainSnapshot();

        $this->project($requester);
        $this->project($requester);

        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_the_action_refuses_to_run_inside_an_outer_transaction(): void
    {
        $requester = $this->makeActor('Requester');

        DB::beginTransaction();

        try {
            $this->expectException(LogicException::class);
            $this->project($requester);
        } finally {
            DB::rollBack();
        }
    }

    // ------------------------------------------------------------------
    // Fixtures

    private function project(ActorReference $requester): array
    {
        return (new FollowMyRequestsAndAccesses())->execute($requester->id);
    }

    private function createRequest(ActorReference $requester, AccessProfile $profile, ?int $durationSeconds = null): AccessRequest
    {
        return (new CreateAccessRequest())->execute(
            $requester->id,
            $profile->id,
            'Needed for the quarter close.',
            $durationSeconds,
        );
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
     * @return array{0: AccessRequest, 1: GrantedAccess, 2: GrantConfirmation}
     */
    private function grantedStandardRequest(ActorReference $requester, ?AccessProfile $profile = null): array
    {
        $request = $this->createRequest($requester, $profile ?? $this->makeProfile($this->resource));
        $this->decide($request, $this->owner);
        $confirmation = (new ConfirmExternalAccessGrant())->execute($request->id, $this->owner->id);
        $access = GrantedAccess::query()->where('grant_confirmation_id', $confirmation->id)->firstOrFail();

        return [$request->fresh(), $access, $confirmation->fresh()];
    }

    /**
     * A concluded Privileged request whose facts carry controlled instants: the
     * access ends at $validUntil and, optionally, a revocation was recorded at
     * $revokedAt. Used where the real Actions cannot place facts in the past.
     *
     * @return array{request: AccessRequest, access: GrantedAccess, confirmation: GrantConfirmation, revocation: ?RevocationConfirmation}
     */
    private function concludedPrivilegedAccess(
        ActorReference $requester,
        CarbonImmutable $validUntil,
        ?CarbonImmutable $revokedAt = null,
    ): array {
        $grantedAt = $validUntil->subSeconds(self::PRIVILEGED_DURATION_SECONDS);

        $request = $this->makeAccessRequest(
            $requester,
            $this->makeProfile($this->resource, 'privileged'),
            'S4',
            self::PRIVILEGED_DURATION_SECONDS,
        );
        $request->requested_at = $grantedAt->subHours(2);
        $request->save();

        $this->makeDecision($request, $this->owner, 'resource_owner', decidedAt: $grantedAt->subMinutes(90));
        $this->makeDecision($request, $this->governance, 'governance', decidedAt: $grantedAt->subMinutes(60));

        $confirmation = new GrantConfirmation();
        $confirmation->access_request_id = $request->id;
        $confirmation->actor_reference_id = $this->owner->id;
        $confirmation->recorded_at = $grantedAt;
        $confirmation->save();

        $access = new GrantedAccess();
        $access->grant_confirmation_id = $confirmation->id;
        $access->valid_until_at = $validUntil;
        $access->save();

        $revocation = null;

        if ($revokedAt !== null) {
            $revocation = new RevocationConfirmation();
            $revocation->granted_access_id = $access->id;
            $revocation->actor_reference_id = $this->owner->id;
            $revocation->recorded_at = $revokedAt;
            $revocation->save();
        }

        return ['request' => $request, 'access' => $access, 'confirmation' => $confirmation, 'revocation' => $revocation];
    }

    /** A requester with requests covering every history kind. */
    private function richRequesterFixture(): ActorReference
    {
        $requester = $this->makeActor('Requester');
        $now = CarbonImmutable::now()->startOfSecond();

        [, $access] = $this->grantedStandardRequest($requester);
        (new RecordExternalAccessRevocation())->execute($access->id, $this->owner->id);
        $this->grantedStandardRequest($requester);
        $rejected = $this->createRequest($requester, $this->makeProfile($this->resource));
        $this->decide($rejected, $this->owner, 'rejected', 'No.');
        $this->concludedPrivilegedAccess($requester, $now->subHours(3));
        $this->concludedPrivilegedAccess($requester, $now->subHours(3), $now->subHours(2));
        $this->createRequest($requester, $this->makeProfile($this->resource));

        $other = $this->makeActor('Other requester');
        $this->grantedStandardRequest($other);

        return $requester;
    }

    // ------------------------------------------------------------------
    // Projection helpers and assertions

    private function requestIn(array $projection, string $requestId): array
    {
        foreach ($projection['requests'] as $request) {
            if ($request['request_id'] === $requestId) {
                return $request;
            }
        }

        $this->fail("Request [{$requestId}] is not in the projection.");
    }

    /** @return list<array<string, mixed>> */
    private function entriesOf(array $request, string $kind): array
    {
        return array_values(array_filter(
            $request['history'],
            static fn (array $entry): bool => $entry['kind'] === $kind
        ));
    }

    private function onlyEntry(array $request, string $kind): array
    {
        $entries = $this->entriesOf($request, $kind);
        $this->assertCount(1, $entries, "Expected exactly one [{$kind}] entry.");

        return $entries[0];
    }

    /** @return list<string> */
    private function kinds(array $request): array
    {
        return array_column($request['history'], 'kind');
    }

    private function assertExpirationMilestone(array $request, CarbonImmutable $validUntil): void
    {
        $expiration = $this->onlyEntry($request, 'expiration');

        $this->assertNull($expiration['fact_id']);
        $this->assertTrue($validUntil->equalTo($expiration['occurred_at']));
        $this->assertTrue($validUntil->equalTo($request['granted_access']['valid_until_at']));
        $this->assertNull($expiration['actor']);
        $this->assertNull($expiration['stage']);
        $this->assertNull($expiration['outcome']);
        $this->assertNull($expiration['justification']);
    }

    private function assertRevocationEntry(array $request, RevocationConfirmation $revocation, CarbonImmutable $recordedAt): void
    {
        $entry = $this->onlyEntry($request, 'revocation_confirmation');

        $this->assertSame($revocation->id, $entry['fact_id']);
        $this->assertTrue($recordedAt->equalTo($entry['occurred_at']));
        $this->assertSame(['id' => $this->owner->id, 'display_name' => 'Resource Owner'], $entry['actor']);
        $this->assertNull($entry['stage']);
        $this->assertNull($entry['outcome']);
        $this->assertNull($entry['justification']);
    }

    /** Only arrays, scalars, null and immutable instants: nothing that can reach the database. */
    private function assertMaterialized(mixed $value, string $path = 'projection'): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertMaterialized($item, $path.'.'.$key);
            }

            return;
        }

        if ($value === null || is_scalar($value)) {
            return;
        }

        $this->assertInstanceOf(CarbonImmutable::class, $value, "{$path} is not a plain materialized value.");
    }

    private function assertNoKeyAnywhere(array $value, string $forbiddenKey): void
    {
        foreach ($value as $key => $item) {
            $this->assertNotSame($forbiddenKey, $key);

            if (is_array($item)) {
                $this->assertNoKeyAnywhere($item, $forbiddenKey);
            }
        }
    }

    /** @return list<string> */
    private function grantedAccessIdsOf(ActorReference $requester): array
    {
        return DB::table('granted_accesses')
            ->join('grant_confirmations', 'grant_confirmations.id', '=', 'granted_accesses.grant_confirmation_id')
            ->join('access_requests', 'access_requests.id', '=', 'grant_confirmations.access_request_id')
            ->where('access_requests.requester_actor_reference_id', $requester->id)
            ->pluck('granted_accesses.id')
            ->all();
    }

    /**
     * Every preserved fact of the requester's requests, as projected fact ids.
     *
     * @return list<string>
     */
    private function factIdsOf(ActorReference $requester): array
    {
        $requestIds = AccessRequest::query()->where('requester_actor_reference_id', $requester->id)->pluck('id')->all();
        $grantIds = GrantConfirmation::query()->whereIn('access_request_id', $requestIds)->pluck('id')->all();
        $accessIds = GrantedAccess::query()->whereIn('grant_confirmation_id', $grantIds)->pluck('id')->all();

        return array_merge(
            $requestIds,
            Decision::query()->whereIn('access_request_id', $requestIds)->pluck('id')->all(),
            $grantIds,
            RevocationConfirmation::query()->whereIn('granted_access_id', $accessIds)->pluck('id')->all(),
        );
    }

    /** @return array<string, string> */
    private function domainSnapshot(): array
    {
        $snapshot = [];

        foreach (self::DOMAIN_TABLES as $table) {
            $snapshot[$table] = json_encode(DB::table($table)->orderByRaw('1')->get()->all(), JSON_THROW_ON_ERROR);
        }

        return $snapshot;
    }
}
