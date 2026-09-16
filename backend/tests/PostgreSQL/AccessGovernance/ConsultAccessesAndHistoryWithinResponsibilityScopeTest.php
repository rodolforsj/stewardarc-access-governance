<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Actions\ConfirmExternalAccessGrant;
use App\AccessGovernance\Actions\ConsultAccessesAndHistoryWithinResponsibilityScope;
use App\AccessGovernance\Actions\CreateAccessRequest;
use App\AccessGovernance\Actions\DecideAccessRequest;
use App\AccessGovernance\Actions\RecordExternalAccessRevocation;
use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\ActorReference;
use App\Models\Decision;
use App\Models\GovernanceMembership;
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
 * UC-006 — Resource Owner and Governance consult Access Requests, Granted
 * Accesses (RF-010) and Functional History (RF-011) within their current
 * responsibility scope (CA-020, CA-021), read under ADR-008.
 */
final class ConsultAccessesAndHistoryWithinResponsibilityScopeTest extends PostgresTestCase
{
    private const PRIVILEGED_DURATION_SECONDS = 3600;

    private ActorReference $ownerA;

    private ActorReference $ownerB;

    private ActorReference $governance;

    private Resource $resourceA;

    private Resource $resourceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerA = $this->makeActor('Owner A');
        $this->ownerB = $this->makeActor('Owner B');
        $this->governance = $this->makeActor('Governance Member');
        $this->makeGovernanceMembership($this->governance);
        $this->resourceA = $this->makeResource($this->ownerA, 'Payroll');
        $this->resourceB = $this->makeResource($this->ownerB, 'Ledger');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_actor_without_responsibility_scope_gets_an_empty_projection(): void
    {
        $requester = $this->makeActor('Requester only');
        $this->grantedRequest($requester, $this->makeProfile($this->resourceA), $this->ownerA);
        $this->grantedRequest($requester, $this->makeProfile($this->resourceB, 'privileged'), $this->ownerB, self::PRIVILEGED_DURATION_SECONDS);
        $bystander = $this->makeActor('No scope');

        // Being a requester grants no UC-006 scope over one's own requests.
        foreach ([$requester->id, $bystander->id, (string) Str::uuid7()] as $actorId) {
            $projection = (new ConsultAccessesAndHistoryWithinResponsibilityScope())->execute($actorId);

            $this->assertInstanceOf(CarbonImmutable::class, $projection['projection_reference_at']);
            $this->assertSame([], $projection['requests']);
        }
    }

    /** CA-020: the Resource Owner sees every request of their resources, in any state, and nothing else. */
    public function test_a_resource_owner_sees_requests_accesses_and_histories_of_their_resources_only(): void
    {
        $requester = $this->makeActor('Requester');
        $ownStates = $this->requestsInEveryState($requester, $this->resourceA, $this->ownerA);
        $otherStates = $this->requestsInEveryState($requester, $this->resourceB, $this->ownerB);

        $projection = $this->project($this->ownerA);

        $this->assertSame(
            $this->sortedIds($ownStates),
            $this->sortedIds(array_column($projection['requests'], 'request_id'))
        );

        foreach ($ownStates as $state => $requestId) {
            $projected = $this->requestIn($projection, $requestId);
            $this->assertSame($state, $projected['current_state']);
            $this->assertSame($this->resourceA->id, $projected['resource_id']);
            $this->assertSame('Payroll', $projected['resource_name']);
            $this->assertSame($state === 'S4', $projected['granted_access'] !== null);
        }

        $this->assertEqualsCanonicalizing(
            $this->grantedAccessIdsOf(array_values($ownStates)),
            $this->projectedAccessIds($projection)
        );
        $this->assertEqualsCanonicalizing($this->factIdsOf(array_values($ownStates)), $this->projectedFactIds($projection));
        $this->assertSame(
            [],
            array_values(array_intersect($this->factIdsOf(array_values($otherStates)), $this->projectedFactIds($projection)))
        );
        $this->assertSame(
            [],
            array_values(array_intersect($this->grantedAccessIdsOf(array_values($otherStates)), $this->projectedAccessIds($projection)))
        );
    }

    /** CA-020: Governance sees the Privileged universe of every resource and no Standard request. */
    public function test_a_governance_member_sees_privileged_requests_accesses_and_histories_only(): void
    {
        $requester = $this->makeActor('Requester');
        $statesA = $this->requestsInEveryState($requester, $this->resourceA, $this->ownerA);
        $statesB = $this->requestsInEveryState($requester, $this->resourceB, $this->ownerB);
        $privilegedS1 = $this->createRequest($requester, $this->makeProfile($this->resourceB, 'privileged'), self::PRIVILEGED_DURATION_SECONDS);
        [$privilegedS4] = $this->grantedRequest($requester, $this->makeProfile($this->resourceA, 'privileged'), $this->ownerA, self::PRIVILEGED_DURATION_SECONDS);

        $expected = [$statesA['S2'], $statesB['S2'], $privilegedS1->id, $privilegedS4->id];
        $standard = [$statesA['S1'], $statesA['S3'], $statesA['S4'], $statesA['S5'], $statesB['S1'], $statesB['S3'], $statesB['S4'], $statesB['S5']];

        $projection = $this->project($this->governance);
        $projectedIds = array_column($projection['requests'], 'request_id');

        $this->assertSame($this->sortedIds($expected), $this->sortedIds($projectedIds));
        $this->assertSame([], array_values(array_intersect($standard, $projectedIds)));

        foreach ($projection['requests'] as $projected) {
            $this->assertSame('privileged', $projected['approval_flow']);
        }

        $this->assertEqualsCanonicalizing($this->grantedAccessIdsOf($expected), $this->projectedAccessIds($projection));
        $this->assertNotEmpty($this->projectedAccessIds($projection));
        $this->assertEqualsCanonicalizing($this->factIdsOf($expected), $this->projectedFactIds($projection));
        $this->assertSame([], array_values(array_intersect($this->factIdsOf($standard), $this->projectedFactIds($projection))));
    }

    /** The Governance universe follows the request's flow snapshot, not the current catalog. */
    public function test_the_governance_scope_uses_the_request_flow_snapshot(): void
    {
        $requester = $this->makeActor('Requester');
        $standardProfile = $this->makeProfile($this->resourceA);
        $privilegedProfile = $this->makeProfile($this->resourceB, 'privileged');
        $standard = $this->createRequest($requester, $standardProfile);
        $privileged = $this->createRequest($requester, $privilegedProfile, self::PRIVILEGED_DURATION_SECONDS);

        DB::table('access_profiles')->where('id', $standardProfile->id)->update(['classification' => 'privileged']);
        DB::table('access_profiles')->where('id', $privilegedProfile->id)->update(['classification' => 'standard']);

        $projection = $this->project($this->governance);

        $this->assertSame([$privileged->id], array_column($projection['requests'], 'request_id'));
        $this->assertNotContains($standard->id, array_column($projection['requests'], 'request_id'));
    }

    /** RN04 is a decision rule: consultation does not exclude the actor's own requests. */
    public function test_the_actors_own_requests_are_not_excluded_from_their_scope(): void
    {
        $ownPrivileged = $this->createRequest($this->governance, $this->makeProfile($this->resourceA, 'privileged'), self::PRIVILEGED_DURATION_SECONDS);
        $this->decide($ownPrivileged, $this->ownerA);

        $this->assertSame([$ownPrivileged->id], array_column($this->project($this->governance)['requests'], 'request_id'));
        $this->assertSame(
            ['id' => $this->governance->id, 'display_name' => 'Governance Member'],
            $this->project($this->governance)['requests'][0]['requester']
        );

        // A requester who later becomes the Resource Owner of the resource.
        $requester = $this->makeActor('Future owner');
        $ownStandard = $this->createRequest($requester, $this->makeProfile($this->resourceB));
        DB::table('resources')->where('id', $this->resourceB->id)->update(['resource_owner_actor_reference_id' => $requester->id]);

        $this->assertSame([$ownStandard->id], array_column($this->project($requester)['requests'], 'request_id'));
    }

    /** ADR-007: both authorities in one actor give the union of both scopes, without duplicates. */
    public function test_an_owner_who_is_also_governance_gets_the_union_without_duplicates(): void
    {
        $dual = $this->makeActor('Owner and Governance');
        $this->makeGovernanceMembership($dual);
        $dualResource = $this->makeResource($dual, 'Treasury');
        $requester = $this->makeActor('Requester');

        $ownStandard = $this->createRequest($requester, $this->makeProfile($dualResource));
        [$ownPrivileged] = $this->grantedRequest($requester, $this->makeProfile($dualResource, 'privileged'), $dual, self::PRIVILEGED_DURATION_SECONDS);
        $otherPrivileged = $this->createRequest($requester, $this->makeProfile($this->resourceB, 'privileged'), self::PRIVILEGED_DURATION_SECONDS);
        $otherStandard = $this->createRequest($requester, $this->makeProfile($this->resourceB));

        $projection = $this->project($dual);
        $projectedIds = array_column($projection['requests'], 'request_id');

        $this->assertSame(
            $this->sortedIds([$ownStandard->id, $ownPrivileged->id, $otherPrivileged->id]),
            $this->sortedIds($projectedIds)
        );
        $this->assertCount(count(array_unique($projectedIds)), $projectedIds);
        $this->assertNotContains($otherStandard->id, $projectedIds);
        $this->assertSame([$this->grantedAccessIdsOf([$ownPrivileged->id])[0]], $this->projectedAccessIds($projection));
    }

    /** The scope is the authority held now: membership removal and ownership transfer apply to the next read. */
    public function test_the_scope_follows_the_current_authority_without_changing_history(): void
    {
        $requester = $this->makeActor('Requester');
        [$privileged] = $this->grantedRequest($requester, $this->makeProfile($this->resourceB, 'privileged'), $this->ownerB, self::PRIVILEGED_DURATION_SECONDS);
        [$standard] = $this->grantedRequest($requester, $this->makeProfile($this->resourceA), $this->ownerA);
        $factsBefore = $this->domainSnapshot();

        $this->assertSame([$privileged->id], array_column($this->project($this->governance)['requests'], 'request_id'));

        GovernanceMembership::query()->whereKey($this->governance->id)->delete();

        $this->assertSame([], $this->project($this->governance)['requests']);

        // Ownership of resource A moves from Owner A to Owner B.
        $this->assertSame([$standard->id], array_column($this->project($this->ownerA)['requests'], 'request_id'));

        DB::table('resources')->where('id', $this->resourceA->id)->update(['resource_owner_actor_reference_id' => $this->ownerB->id]);

        $this->assertSame([], $this->project($this->ownerA)['requests']);
        $projectionB = $this->project($this->ownerB);
        $this->assertSame(
            $this->sortedIds([$privileged->id, $standard->id]),
            $this->sortedIds(array_column($projectionB['requests'], 'request_id'))
        );

        // Past facts keep their recorded actors.
        $transferred = $this->requestIn($projectionB, $standard->id);
        $this->assertSame($this->ownerA->id, $this->onlyEntry($transferred, 'decision')['actor']['id']);
        $this->assertSame($this->ownerA->id, $this->onlyEntry($transferred, 'grant_confirmation')['actor']['id']);

        $factsAfter = $this->domainSnapshot();
        foreach (['access_requests', 'decisions', 'grant_confirmations', 'granted_accesses', 'revocation_confirmations'] as $table) {
            $this->assertSame($factsBefore[$table], $factsAfter[$table]);
        }
    }

    /** Every request identifies its requester by id and display name only (RNF-005). */
    public function test_each_request_identifies_its_requester(): void
    {
        $alice = $this->makeActor('Alice');
        $bruno = $this->makeActor('Bruno');
        $fromAlice = $this->createRequest($alice, $this->makeProfile($this->resourceA));
        $fromBruno = $this->createRequest($bruno, $this->makeProfile($this->resourceA));

        $projection = $this->project($this->ownerA);

        $this->assertSame(['id' => $alice->id, 'display_name' => 'Alice'], $this->requestIn($projection, $fromAlice->id)['requester']);
        $this->assertSame(['id' => $bruno->id, 'display_name' => 'Bruno'], $this->requestIn($projection, $fromBruno->id)['requester']);

        $projected = $this->requestIn($projection, $fromAlice->id);
        $persisted = AccessRequest::query()->findOrFail($fromAlice->id);

        $this->assertSame([
            'request_id',
            'requester',
            'access_profile_id',
            'access_profile_name',
            'resource_id',
            'resource_name',
            'justification',
            'approval_flow',
            'requested_duration_seconds',
            'current_state',
            'requested_at',
            'granted_access',
            'history',
        ], array_keys($projected));
        $this->assertSame($persisted->access_profile_id, $projected['access_profile_id']);
        $this->assertSame('Profile', $projected['access_profile_name']);
        $this->assertSame($persisted->justification, $projected['justification']);
        $this->assertSame('standard', $projected['approval_flow']);
        $this->assertNull($projected['requested_duration_seconds']);
        $this->assertTrue($persisted->requested_at->equalTo($projected['requested_at']));
        $this->assertSame(['id' => $alice->id, 'display_name' => 'Alice'], $this->onlyEntry($projected, 'request_registered')['actor']);

        $this->assertNoKeyAnywhere($projection, 'external_identity_key');
        $identityKeys = ActorReference::query()->pluck('external_identity_key')->all();
        array_walk_recursive($projection, function (mixed $value) use ($identityKeys): void {
            $this->assertNotContains($value, $identityKeys, 'An external identity key leaked into the projection.');
        });
    }

    /** CA-021: decisions, the rejection justification and both confirmations keep their actor and time. */
    public function test_the_history_preserves_actors_times_and_the_rejection_justification(): void
    {
        $requester = $this->makeActor('Requester');
        $privileged = $this->createRequest($requester, $this->makeProfile($this->resourceA, 'privileged'), self::PRIVILEGED_DURATION_SECONDS);
        $ownerApproval = $this->decide($privileged, $this->ownerA);
        $governanceApproval = $this->decide($privileged, $this->governance);
        $grant = (new ConfirmExternalAccessGrant())->execute($privileged->id, $this->ownerA->id);
        $access = GrantedAccess::query()->where('grant_confirmation_id', $grant->id)->firstOrFail();
        $revocation = (new RecordExternalAccessRevocation())->execute($access->id, $this->ownerA->id);

        $rejected = $this->createRequest($requester, $this->makeProfile($this->resourceB, 'privileged'), self::PRIVILEGED_DURATION_SECONDS);
        $this->decide($rejected, $this->ownerB);
        $justification = "  Privileged access is not justified.\nAsk again next quarter.  ";
        $rejection = $this->decide($rejected, $this->governance, 'rejected', $justification);

        $projection = $this->project($this->governance);

        $granted = $this->requestIn($projection, $privileged->id);
        $this->assertEqualsCanonicalizing(
            ['request_registered', 'decision', 'decision', 'grant_confirmation', 'revocation_confirmation'],
            $this->kinds($granted)
        );
        $this->assertSame('A3', $granted['granted_access']['state']);

        $registration = $this->onlyEntry($granted, 'request_registered');
        $this->assertSame($privileged->id, $registration['fact_id']);
        $this->assertTrue($privileged->fresh()->requested_at->equalTo($registration['occurred_at']));
        $this->assertSame(['id' => $requester->id, 'display_name' => 'Requester'], $registration['actor']);

        $decisions = array_column($this->entriesOf($granted, 'decision'), null, 'fact_id');
        $this->assertDecisionEntry($decisions[$ownerApproval->id], $ownerApproval, 'resource_owner', 'approved', null, $this->ownerA);
        $this->assertDecisionEntry($decisions[$governanceApproval->id], $governanceApproval, 'governance', 'approved', null, $this->governance);

        $grantEntry = $this->onlyEntry($granted, 'grant_confirmation');
        $this->assertSame($grant->id, $grantEntry['fact_id']);
        $this->assertTrue($grant->fresh()->recorded_at->equalTo($grantEntry['occurred_at']));
        $this->assertSame(['id' => $this->ownerA->id, 'display_name' => 'Owner A'], $grantEntry['actor']);

        $this->assertRevocationEntry($granted, $revocation, $revocation->fresh()->recorded_at, $this->ownerA);

        $rejectedProjection = $this->requestIn($projection, $rejected->id);
        $this->assertSame('S5', $rejectedProjection['current_state']);
        $this->assertNull($rejectedProjection['granted_access']);
        $rejectionEntry = array_column($this->entriesOf($rejectedProjection, 'decision'), null, 'fact_id')[$rejection->id];
        $this->assertDecisionEntry($rejectionEntry, $rejection, 'governance', 'rejected', $justification, $this->governance);

        foreach ($projection['requests'] as $projected) {
            $occurredAt = array_map(static fn (array $entry): string => $entry['occurred_at']->format('Y-m-d H:i:s.u'), $projected['history']);
            $sorted = $occurredAt;
            sort($sorted);
            $this->assertSame($sorted, $occurredAt, 'History must be chronological.');
        }
    }

    /** A1: accesses within their validity are active. */
    public function test_accesses_within_their_validity_are_active(): void
    {
        $requester = $this->makeActor('Requester');
        [$standard, $standardAccess] = $this->grantedRequest($requester, $this->makeProfile($this->resourceA), $this->ownerA);
        [$privileged, $privilegedAccess, $confirmation] = $this->grantedRequest($requester, $this->makeProfile($this->resourceA, 'privileged'), $this->ownerA, self::PRIVILEGED_DURATION_SECONDS);

        $projection = $this->project($this->ownerA);

        $projectedStandard = $this->requestIn($projection, $standard->id);
        $this->assertSame('S4', $projectedStandard['current_state']);
        $this->assertSame(['id' => $standardAccess->id, 'valid_until_at' => null, 'state' => 'A1'], $projectedStandard['granted_access']);
        $this->assertSame([], $this->entriesOf($projectedStandard, 'expiration'));

        $projectedPrivileged = $this->requestIn($projection, $privileged->id);
        $this->assertSame($privilegedAccess->id, $projectedPrivileged['granted_access']['id']);
        $this->assertSame('A1', $projectedPrivileged['granted_access']['state']);
        $this->assertTrue(
            $confirmation->recorded_at->addSeconds(self::PRIVILEGED_DURATION_SECONDS)->equalTo($projectedPrivileged['granted_access']['valid_until_at'])
        );
        $this->assertTrue($projection['projection_reference_at']->lessThan($projectedPrivileged['granted_access']['valid_until_at']));
        $this->assertSame([], $this->entriesOf($projectedPrivileged, 'expiration'));
    }

    /** CA-017 / RF-008: an expired Privileged access is A2 with a derived, actorless milestone. */
    public function test_an_expired_privileged_access_is_a2_with_a_derived_expiration_milestone(): void
    {
        $requester = $this->makeActor('Requester');
        $validUntil = CarbonImmutable::now()->subHours(2)->startOfSecond();
        $fixture = $this->concludedPrivilegedAccess($requester, $this->resourceB, $this->ownerB, $validUntil);

        foreach ([$this->ownerB, $this->governance] as $actor) {
            $projection = $this->project($actor);
            $projected = $this->requestIn($projection, $fixture['request']->id);

            $this->assertTrue($projection['projection_reference_at']->greaterThanOrEqualTo($validUntil));
            $this->assertSame('S4', $projected['current_state']);
            $this->assertSame('A2', $projected['granted_access']['state']);
            $this->assertEqualsCanonicalizing(
                ['request_registered', 'decision', 'decision', 'grant_confirmation', 'expiration'],
                $this->kinds($projected)
            );
            $this->assertExpirationMilestone($projected, $validUntil);
        }

        $this->assertSame(0, RevocationConfirmation::query()->count());
    }

    /** A3: a revocation before the validity end ends the access; no expiration is added later. */
    public function test_an_access_revoked_before_its_validity_end_stays_a3_without_expiration(): void
    {
        $requester = $this->makeActor('Requester');
        $validUntil = CarbonImmutable::now()->subHours(2)->startOfSecond();
        $revokedAt = $validUntil->subMinutes(30);
        $fixture = $this->concludedPrivilegedAccess($requester, $this->resourceA, $this->ownerA, $validUntil, $revokedAt);

        $projection = $this->project($this->ownerA);
        $projected = $this->requestIn($projection, $fixture['request']->id);

        $this->assertTrue($projection['projection_reference_at']->greaterThan($validUntil));
        $this->assertSame('A3', $projected['granted_access']['state']);
        $this->assertSame([], $this->entriesOf($projected, 'expiration'));
        $this->assertRevocationEntry($projected, $fixture['revocation'], $revokedAt, $this->ownerA);
    }

    /** CA-018 scenario B: a revocation after the expiration keeps A2 and both entries. */
    public function test_a_revocation_recorded_after_the_expiration_keeps_a2_with_both_entries(): void
    {
        $requester = $this->makeActor('Requester');
        $validUntil = CarbonImmutable::now()->subHours(2)->startOfSecond();
        $revokedAt = $validUntil->addMinutes(30);
        $fixture = $this->concludedPrivilegedAccess($requester, $this->resourceA, $this->ownerA, $validUntil, $revokedAt);

        $projected = $this->requestIn($this->project($this->governance), $fixture['request']->id);

        $this->assertSame('A2', $projected['granted_access']['state']);
        $this->assertExpirationMilestone($projected, $validUntil);
        $this->assertRevocationEntry($projected, $fixture['revocation'], $revokedAt, $this->ownerA);
    }

    /**
     * ADR-008 and ADR-009: every access of one execution is derived against the
     * single PostgreSQL reference instant; the PHP clock plays no part, whether
     * it is moved far ahead or far behind.
     */
    public function test_every_access_is_derived_against_the_single_postgresql_reference_instant(): void
    {
        $dual = $this->makeActor('Owner and Governance');
        $this->makeGovernanceMembership($dual);
        $dualResource = $this->makeResource($dual, 'Treasury');
        $requester = $this->makeActor('Requester');
        $now = CarbonImmutable::now()->startOfSecond();

        [$activeStandard] = $this->grantedRequest($requester, $this->makeProfile($dualResource), $dual);
        [$activePrivileged] = $this->grantedRequest($requester, $this->makeProfile($this->resourceB, 'privileged'), $this->ownerB, self::PRIVILEGED_DURATION_SECONDS);
        [$revokedStandard, $revokedAccess] = $this->grantedRequest($requester, $this->makeProfile($dualResource), $dual);
        (new RecordExternalAccessRevocation())->execute($revokedAccess->id, $dual->id);

        $expired = $this->concludedPrivilegedAccess($requester, $this->resourceB, $this->ownerB, $now->subHours(3));
        $early = $this->concludedPrivilegedAccess($requester, $this->resourceA, $this->ownerA, $now->subHours(3), $now->subHours(4));
        $late = $this->concludedPrivilegedAccess($requester, $dualResource, $dual, $now->subHours(3), $now->subHours(2));
        $boundary = $this->concludedPrivilegedAccess($requester, $this->resourceB, $this->ownerB, $now->subHours(3), $now->subHours(3));

        $expectedStates = [
            $activeStandard->id => 'A1',
            $activePrivileged->id => 'A1',
            $revokedStandard->id => 'A3',
            $expired['request']->id => 'A2',
            $early['request']->id => 'A3',
            $late['request']->id => 'A2',
            $boundary['request']->id => 'A2',
        ];
        ksort($expectedStates);

        foreach ([$now->addYear(), $now->subYear()] as $phpNow) {
            $before = CarbonImmutable::parse(DB::selectOne('select clock_timestamp() as value')->value);
            Carbon::setTestNow($phpNow);

            try {
                $projection = $this->project($dual);
            } finally {
                Carbon::setTestNow();
            }

            $after = CarbonImmutable::parse(DB::selectOne('select clock_timestamp() as value')->value);
            $referenceAt = $projection['projection_reference_at'];

            $this->assertTrue($referenceAt->betweenIncluded($before, $after), 'The reference must come from PostgreSQL, not the PHP clock.');

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

                $expirations = $this->entriesOf($request, 'expiration');
                $this->assertCount($request['granted_access']['state'] === 'A2' ? 1 : 0, $expirations);
            }

            ksort($states);
            $this->assertSame($expectedStates, $states);
        }
    }

    /** ADR-008: the result is fully materialized and reading it runs no query. */
    public function test_the_projection_is_fully_materialized_and_triggers_no_query_after_it_returns(): void
    {
        $projection = $this->project($this->richScopeFixture());

        $this->assertNotEmpty($projection['requests']);
        $this->assertMaterialized($projection);
        $this->assertSame(0, DB::transactionLevel());

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $encoded = json_encode($projection, JSON_THROW_ON_ERROR);

        $this->assertNotSame('', $encoded);
        $this->assertSame(0, $queries);
    }

    /** ADR-008: one read transaction, scope reads inside it, no locks, no writes, no N+1. */
    public function test_the_projection_reads_only_inside_its_read_transaction_without_locks(): void
    {
        $actor = $this->richScopeFixture();

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
        });

        $projection = $this->project($actor);

        $this->assertGreaterThanOrEqual(6, count($projection['requests']));
        $this->assertSame('set transaction isolation level repeatable read, read only', $queries[0]['sql']);
        $this->assertSame('select transaction_timestamp() as projection_reference_at', $queries[1]['sql']);
        // The Governance authority is read inside the same snapshot.
        $this->assertStringContainsString('governance_memberships', $queries[2]['sql']);
        // Eager loading keeps the number of queries independent of the number of requests.
        $this->assertLessThanOrEqual(14, count($queries));

        foreach ($queries as $query) {
            $this->assertSame(1, $query['level'], 'Query outside the read transaction: '.$query['sql']);
            $this->assertDoesNotMatchRegularExpression('/\bfor\s+(no\s+key\s+)?(update|share|key\s+share)\b/i', $query['sql']);
            $this->assertStringNotContainsStringIgnoringCase('pg_advisory', $query['sql']);
            $this->assertMatchesRegularExpression('/^(select|set transaction)\b/i', $query['sql']);
        }
    }

    /** RNF-003: consulting changes no functional fact. */
    public function test_the_projection_writes_nothing(): void
    {
        $actor = $this->richScopeFixture();
        $before = $this->domainSnapshot();

        $this->project($actor);
        $this->project($this->ownerB);
        $this->project($this->governance);

        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_requests_are_ordered_by_requested_at_then_id(): void
    {
        $requester = $this->makeActor('Requester');
        $at = CarbonImmutable::now()->subDay()->startOfSecond();
        $ids = [];

        foreach ([$at->addMinutes(5), $at, $at, $at->addMinutes(1)] as $requestedAt) {
            $request = $this->makeAccessRequest($requester, $this->makeProfile($this->resourceA), 'S1');
            $request->requested_at = $requestedAt;
            $request->save();
            $ids[] = [$requestedAt->format('Y-m-d H:i:s'), $request->id];
        }

        usort($ids, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

        $this->assertSame(array_column($ids, 1), array_column($this->project($this->ownerA)['requests'], 'request_id'));
    }

    public function test_the_action_refuses_to_run_inside_an_outer_transaction(): void
    {
        DB::beginTransaction();

        try {
            $this->expectException(LogicException::class);
            $this->project($this->ownerA);
        } finally {
            DB::rollBack();
        }
    }

    // ------------------------------------------------------------------
    // Fixtures

    private function project(ActorReference $actor): array
    {
        return (new ConsultAccessesAndHistoryWithinResponsibilityScope())->execute($actor->id);
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
     * A request taken to S4 through the real Actions; Privileged requests also
     * pass the Governance step.
     *
     * @return array{0: AccessRequest, 1: GrantedAccess, 2: GrantConfirmation}
     */
    private function grantedRequest(
        ActorReference $requester,
        AccessProfile $profile,
        ActorReference $owner,
        ?int $durationSeconds = null,
    ): array {
        $request = $this->createRequest($requester, $profile, $durationSeconds);
        $this->approveUntilAwaitingGrant($request, $owner, $durationSeconds === null ? null : $this->governance);
        $confirmation = (new ConfirmExternalAccessGrant())->execute($request->id, $owner->id);
        $access = GrantedAccess::query()->where('grant_confirmation_id', $confirmation->id)->firstOrFail();

        return [$request->fresh(), $access, $confirmation->fresh()];
    }

    /**
     * One request per state S1–S5 on the resource, through the real Actions.
     * S2 is Privileged; the others are Standard.
     *
     * @return array<string, string> request id per state
     */
    private function requestsInEveryState(ActorReference $requester, Resource $resource, ActorReference $owner): array
    {
        $s1 = $this->createRequest($requester, $this->makeProfile($resource));
        $s2 = $this->createRequest($requester, $this->makeProfile($resource, 'privileged'), self::PRIVILEGED_DURATION_SECONDS);
        $this->decide($s2, $owner);
        $s3 = $this->createRequest($requester, $this->makeProfile($resource));
        $this->decide($s3, $owner);
        [$s4] = $this->grantedRequest($requester, $this->makeProfile($resource), $owner);
        $s5 = $this->createRequest($requester, $this->makeProfile($resource));
        $this->decide($s5, $owner, 'rejected', 'Not part of the role.');

        return ['S1' => $s1->id, 'S2' => $s2->id, 'S3' => $s3->id, 'S4' => $s4->id, 'S5' => $s5->id];
    }

    /**
     * A concluded Privileged request whose facts carry controlled instants: the
     * access ends at $validUntil and, optionally, a revocation was recorded at
     * $revokedAt by the Resource Owner.
     *
     * @return array{request: AccessRequest, access: GrantedAccess, confirmation: GrantConfirmation, revocation: ?RevocationConfirmation}
     */
    private function concludedPrivilegedAccess(
        ActorReference $requester,
        Resource $resource,
        ActorReference $owner,
        CarbonImmutable $validUntil,
        ?CarbonImmutable $revokedAt = null,
    ): array {
        $grantedAt = $validUntil->subSeconds(self::PRIVILEGED_DURATION_SECONDS);

        $request = $this->makeAccessRequest(
            $requester,
            $this->makeProfile($resource, 'privileged'),
            'S4',
            self::PRIVILEGED_DURATION_SECONDS,
        );
        $request->requested_at = $grantedAt->subHours(2);
        $request->save();

        $this->makeDecision($request, $owner, 'resource_owner', decidedAt: $grantedAt->subMinutes(90));
        $this->makeDecision($request, $this->governance, 'governance', decidedAt: $grantedAt->subMinutes(60));

        $confirmation = new GrantConfirmation();
        $confirmation->access_request_id = $request->id;
        $confirmation->actor_reference_id = $owner->id;
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
            $revocation->actor_reference_id = $owner->id;
            $revocation->recorded_at = $revokedAt;
            $revocation->save();
        }

        return ['request' => $request, 'access' => $access, 'confirmation' => $confirmation, 'revocation' => $revocation];
    }

    /** An actor holding both authorities, with every history kind in scope and data out of scope. */
    private function richScopeFixture(): ActorReference
    {
        $dual = $this->makeActor('Owner and Governance');
        $this->makeGovernanceMembership($dual);
        $dualResource = $this->makeResource($dual, 'Treasury');
        $requester = $this->makeActor('Requester');
        $now = CarbonImmutable::now()->startOfSecond();

        $this->requestsInEveryState($requester, $dualResource, $dual);
        [, $access] = $this->grantedRequest($requester, $this->makeProfile($dualResource), $dual);
        (new RecordExternalAccessRevocation())->execute($access->id, $dual->id);
        $this->concludedPrivilegedAccess($requester, $this->resourceB, $this->ownerB, $now->subHours(3));
        $this->concludedPrivilegedAccess($requester, $this->resourceA, $this->ownerA, $now->subHours(3), $now->subHours(2));
        $this->requestsInEveryState($requester, $this->resourceB, $this->ownerB);

        return $dual;
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

    /**
     * @param  iterable<string>  $ids
     * @return list<string>
     */
    private function sortedIds(iterable $ids): array
    {
        $ids = array_values(is_array($ids) ? $ids : iterator_to_array($ids));
        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    private function projectedAccessIds(array $projection): array
    {
        return array_values(array_column(array_filter(array_column($projection['requests'], 'granted_access')), 'id'));
    }

    /** @return list<?string> */
    private function projectedFactIds(array $projection): array
    {
        $ids = [];

        foreach ($projection['requests'] as $request) {
            foreach ($request['history'] as $entry) {
                if ($entry['fact_id'] !== null) {
                    $ids[] = $entry['fact_id'];
                }
            }
        }

        return $ids;
    }

    private function assertDecisionEntry(
        array $entry,
        Decision $decision,
        string $stage,
        string $outcome,
        ?string $justification,
        ActorReference $actor,
    ): void {
        $this->assertSame('decision', $entry['kind']);
        $this->assertSame($decision->id, $entry['fact_id']);
        $this->assertSame($stage, $entry['stage']);
        $this->assertSame($outcome, $entry['outcome']);
        $this->assertSame($justification, $entry['justification']);
        $this->assertSame(['id' => $actor->id, 'display_name' => $actor->display_name], $entry['actor']);
        $this->assertTrue($decision->fresh()->decided_at->equalTo($entry['occurred_at']));
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

    private function assertRevocationEntry(
        array $request,
        RevocationConfirmation $revocation,
        CarbonImmutable $recordedAt,
        ActorReference $recordedBy,
    ): void {
        $entry = $this->onlyEntry($request, 'revocation_confirmation');

        $this->assertSame($revocation->id, $entry['fact_id']);
        $this->assertTrue($recordedAt->equalTo($entry['occurred_at']));
        $this->assertSame(['id' => $recordedBy->id, 'display_name' => $recordedBy->display_name], $entry['actor']);
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

    /**
     * @param  list<string>  $requestIds
     * @return list<string>
     */
    private function grantedAccessIdsOf(array $requestIds): array
    {
        return DB::table('granted_accesses')
            ->join('grant_confirmations', 'grant_confirmations.id', '=', 'granted_accesses.grant_confirmation_id')
            ->whereIn('grant_confirmations.access_request_id', $requestIds)
            ->pluck('granted_accesses.id')
            ->all();
    }

    /**
     * Every preserved fact of the given requests, as projected fact ids.
     *
     * @param  list<string>  $requestIds
     * @return list<string>
     */
    private function factIdsOf(array $requestIds): array
    {
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
