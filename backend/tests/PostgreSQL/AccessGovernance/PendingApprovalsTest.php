<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Actions\ListPendingApprovals;
use App\Models\AccessRequest;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * UC-002 / RF-004 — CA-008: pending approvals are restricted to the applicable
 * scope and authority.
 */
final class PendingApprovalsTest extends PostgresTestCase
{
    private function action(): ListPendingApprovals
    {
        return new ListPendingApprovals();
    }

    /** @return array<int, string> */
    private function pendingIdsFor(string $actorId): array
    {
        return $this->action()->execute($actorId)->pluck('id')->sort()->values()->all();
    }

    public function test_a_resource_owner_sees_the_s1_requests_of_their_own_resource(): void
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $pending = $this->makeAccessRequest($requester, $profile, 'S1');

        $this->assertSame([$pending->id], $this->pendingIdsFor($owner->id));
    }

    public function test_a_resource_owner_does_not_see_requests_of_another_owners_resource(): void
    {
        $owner = $this->makeActor('Owner');
        $otherOwner = $this->makeActor('Other owner');
        $requester = $this->makeActor('Requester');
        $foreignProfile = $this->makeProfile($this->makeResource($otherOwner), 'standard');
        $this->makeAccessRequest($requester, $foreignProfile, 'S1');

        $this->assertSame([], $this->pendingIdsFor($owner->id));
    }

    public function test_a_resource_owner_alone_does_not_see_the_governance_step(): void
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');
        $this->makeAccessRequest($requester, $profile, 'S2');

        $this->assertSame([], $this->pendingIdsFor($owner->id));
    }

    /** Requests that are no longer pending a decision are never actionable. */
    public function test_requests_outside_the_decision_steps_are_not_pending(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');

        foreach (['S3', 'S4', 'S5'] as $state) {
            $this->makeAccessRequest($this->makeActor('Requester '.$state), $profile, $state);
        }

        $this->assertSame([], $this->pendingIdsFor($owner->id));
    }

    public function test_a_governance_member_sees_the_s2_requests(): void
    {
        $governance = $this->makeActor('Governance');
        $this->makeGovernanceMembership($governance);
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), 'privileged');
        $pending = $this->makeAccessRequest($requester, $profile, 'S2');

        $this->assertSame([$pending->id], $this->pendingIdsFor($governance->id));
    }

    public function test_governance_authority_alone_does_not_expose_s1_of_other_resources(): void
    {
        $governance = $this->makeActor('Governance');
        $this->makeGovernanceMembership($governance);
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), 'privileged');
        $this->makeAccessRequest($requester, $profile, 'S1');

        $this->assertSame([], $this->pendingIdsFor($governance->id));
    }

    public function test_an_actor_without_membership_does_not_see_the_governance_step(): void
    {
        $plainActor = $this->makeActor('No authority');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), 'privileged');
        $this->makeAccessRequest($requester, $profile, 'S2');

        $this->assertSame([], $this->pendingIdsFor($plainActor->id));
    }

    public function test_an_actor_with_both_authorities_sees_the_union_without_duplicates(): void
    {
        $actor = $this->makeActor('Owner and Governance');
        $this->makeGovernanceMembership($actor);
        $requester = $this->makeActor('Requester');

        $ownProfile = $this->makeProfile($this->makeResource($actor), 'standard');
        $ownS1 = $this->makeAccessRequest($requester, $ownProfile, 'S1');

        $foreignPrivileged = $this->makeProfile($this->makeResource($this->makeActor('Other owner')), 'privileged');
        $governanceS2 = $this->makeAccessRequest($requester, $foreignPrivileged, 'S2');

        $ownPrivileged = $this->makeProfile($this->makeResource($actor), 'privileged', true, 'Own privileged');
        $ownS2 = $this->makeAccessRequest($this->makeActor('Another requester'), $ownPrivileged, 'S2');

        $expected = collect([$ownS1->id, $governanceS2->id, $ownS2->id])->sort()->values()->all();

        $this->assertSame($expected, $this->pendingIdsFor($actor->id));
        $this->assertCount(3, $this->action()->execute($actor->id));
    }

    /** RN04 also applies to the query: an own request is not actionable. */
    public function test_a_governance_member_does_not_see_their_own_request_as_pending(): void
    {
        $governanceRequester = $this->makeActor('Governance requester');
        $this->makeGovernanceMembership($governanceRequester);
        $otherGovernance = $this->makeActor('Other governance');
        $this->makeGovernanceMembership($otherGovernance);

        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), 'privileged');
        $ownRequest = $this->makeAccessRequest($governanceRequester, $profile, 'S2');

        $this->assertSame([], $this->pendingIdsFor($governanceRequester->id));
        $this->assertSame([$ownRequest->id], $this->pendingIdsFor($otherGovernance->id));
    }

    public function test_a_resource_owner_does_not_see_their_own_request_as_pending(): void
    {
        $owner = $this->makeActor('Owner');
        $otherOwner = $this->makeActor('Other owner');
        $profile = $this->makeProfile($this->makeResource($otherOwner), 'standard');
        $this->makeAccessRequest($owner, $profile, 'S1');

        $this->assertSame([], $this->pendingIdsFor($owner->id));
        $this->assertSame(1, AccessRequest::query()->count());
    }

    public function test_an_actor_without_any_authority_gets_an_empty_collection(): void
    {
        $plainActor = $this->makeActor('No authority');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), 'standard');
        $this->makeAccessRequest($requester, $profile, 'S1');

        $pending = $this->action()->execute($plainActor->id);

        $this->assertCount(0, $pending);
        $this->assertTrue($pending->isEmpty());
    }
}
