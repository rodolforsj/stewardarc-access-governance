<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Actions\ConfirmExternalAccessGrant;
use App\AccessGovernance\Actions\RecordExternalAccessRevocation;
use App\AccessGovernance\Exceptions\RevocationConfirmationViolation;
use App\Models\AccessRequest;
use App\Models\ActorReference;
use App\Models\GrantConfirmation;
use App\Models\GrantedAccess;
use App\Models\RevocationConfirmation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * UC-004 / RF-007 — recording that an access was revoked externally.
 */
final class RecordExternalAccessRevocationTest extends PostgresTestCase
{
    private function action(): RecordExternalAccessRevocation
    {
        return new RecordExternalAccessRevocation();
    }

    /**
     * CA-018 scenario A, Standard: an open-ended active access becomes A3.
     */
    public function test_an_open_ended_active_access_is_revoked_and_becomes_a3(): void
    {
        [$grantedAccess, $owner, $request] = $this->grantedStandardAccess();

        $confirmation = $this->action()->execute($grantedAccess->id, $owner->id);

        $this->assertSame(1, RevocationConfirmation::query()->count());
        $this->assertSame($grantedAccess->id, $confirmation->granted_access_id);
        $this->assertSame($owner->id, $confirmation->actor_reference_id);
        $this->assertNotNull($confirmation->recorded_at);

        $this->assertSame('S4', $request->fresh()->current_state);
        $this->assertSame(1, GrantConfirmation::query()->count());
        $this->assertSame(1, GrantedAccess::query()->count());
        $this->assertNull($grantedAccess->fresh()->valid_until_at);
        $this->assertSame('A3', $this->derivedGrantedAccessState($grantedAccess));
    }

    /** CA-018 scenario A, Privileged: revoked while still within the validity. */
    public function test_a_privileged_access_revoked_within_its_validity_becomes_a3(): void
    {
        [$grantedAccess, $owner] = $this->grantedPrivilegedAccess(3600);
        $validUntil = $grantedAccess->fresh()->valid_until_at;

        $confirmation = $this->action()->execute($grantedAccess->id, $owner->id);

        $this->assertTrue($confirmation->recorded_at->lessThan($validUntil));
        $this->assertSame('A3', $this->derivedGrantedAccessState($grantedAccess));
        $this->assertTrue($grantedAccess->fresh()->valid_until_at->equalTo($validUntil));
    }

    /**
     * CA-018 scenario B: an access already ended by expiration keeps A2, and
     * the confirmation is preserved as an additional historical fact.
     */
    public function test_an_expired_access_keeps_a2_and_preserves_the_confirmation(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S4', 3600);
        $grantedAccess = $this->makeGrantedAccess($request, $owner, Carbon::now()->subHour());

        $this->assertSame('A2', $this->derivedGrantedAccessState($grantedAccess));

        $confirmation = $this->action()->execute($grantedAccess->id, $owner->id);

        $this->assertSame(1, RevocationConfirmation::query()->count());
        $this->assertTrue($confirmation->recorded_at->greaterThanOrEqualTo($grantedAccess->fresh()->valid_until_at));
        $this->assertSame('A2', $this->derivedGrantedAccessState($grantedAccess));
        $this->assertNotNull($grantedAccess->fresh()->valid_until_at);
        $this->assertSame('S4', $request->fresh()->current_state);
    }

    /**
     * The temporal boundary: recorded exactly at `valid_until_at` keeps A2.
     *
     * Test-only orchestration: the Action runs inside an outer transaction, so
     * its functional instant (ADR-009) is that transaction's start, which the
     * test can read beforehand and use as the validity end. The validity end is
     * truncated to seconds, the precision at which the models persist instants.
     */
    public function test_a_confirmation_recorded_exactly_at_the_validity_end_keeps_a2(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S4', 3600);
        $grantedAccess = $this->makeGrantedAccess($request, $owner, Carbon::now()->addHour());

        DB::beginTransaction();

        try {
            DB::update(
                "update granted_accesses set valid_until_at = date_trunc('second', transaction_timestamp()) where id = ?",
                [$grantedAccess->id]
            );
            $boundary = $grantedAccess->fresh()->valid_until_at;

            $confirmation = $this->action()->execute($grantedAccess->id, $owner->id);
            $persisted = RevocationConfirmation::query()->findOrFail($confirmation->id);

            $this->assertTrue($persisted->recorded_at->equalTo($boundary));
            $this->assertSame('A2', $this->derivedGrantedAccessState($grantedAccess));
        } finally {
            DB::rollBack();
        }
    }

    /** An access already in A3 does not become A2 once the validity elapses. */
    public function test_an_access_revoked_while_active_stays_a3_after_the_validity_elapses(): void
    {
        [$grantedAccess, $owner] = $this->grantedPrivilegedAccess(60);

        $this->action()->execute($grantedAccess->id, $owner->id);

        $afterTheEnd = $grantedAccess->fresh()->valid_until_at->addDay();

        $this->assertSame('A3', $this->derivedGrantedAccessState($grantedAccess));
        $this->assertSame('A3', $this->derivedGrantedAccessState($grantedAccess, $afterTheEnd));
    }

    /** RF-007: only the Resource Owner may record the confirmation. */
    public function test_an_unrelated_actor_cannot_record_the_revocation(): void
    {
        [$grantedAccess, , $request] = $this->grantedStandardAccess();
        $stranger = $this->makeActor('Stranger');

        $violation = $this->assertViolation(fn () => $this->action()->execute($grantedAccess->id, $stranger->id));

        $this->assertSame('RF-007', $violation->constraintId);
        $this->assertSame(0, RevocationConfirmation::query()->count());
        $this->assertSame('A1', $this->derivedGrantedAccessState($grantedAccess));
        $this->assertSame('S4', $request->fresh()->current_state);
        $this->assertSame(1, GrantConfirmation::query()->count());
        $this->assertSame(1, GrantedAccess::query()->count());
    }

    /** RF-007: Governance authority does not authorize the confirmation. */
    public function test_a_governance_member_who_is_not_the_owner_cannot_record_the_revocation(): void
    {
        [$grantedAccess] = $this->grantedStandardAccess();
        $governance = $this->makeActor('Governance');
        $this->makeGovernanceMembership($governance);

        $violation = $this->assertViolation(fn () => $this->action()->execute($grantedAccess->id, $governance->id));

        $this->assertSame('RF-007', $violation->constraintId);
        $this->assertSame(0, RevocationConfirmation::query()->count());
    }

    /** An actor holding both authorities records it as Resource Owner. */
    public function test_an_owner_who_is_also_a_governance_member_can_record_the_revocation(): void
    {
        [$grantedAccess, $owner] = $this->grantedStandardAccess();
        $this->makeGovernanceMembership($owner);

        $confirmation = $this->action()->execute($grantedAccess->id, $owner->id);

        $this->assertSame($owner->id, $confirmation->actor_reference_id);
        $this->assertSame(1, RevocationConfirmation::query()->count());
    }

    /** Repeating the operation records no second fact (RNF-009). */
    public function test_repeating_the_revocation_does_not_create_a_second_fact(): void
    {
        [$grantedAccess, $owner, $request] = $this->grantedStandardAccess();

        $first = $this->action()->execute($grantedAccess->id, $owner->id);
        $violation = $this->assertViolation(fn () => $this->action()->execute($grantedAccess->id, $owner->id));

        $this->assertSame('RF-007', $violation->constraintId);
        $this->assertSame(1, RevocationConfirmation::query()->count());
        $this->assertSame($first->id, RevocationConfirmation::query()->firstOrFail()->id);
        $this->assertSame('S4', $request->fresh()->current_state);
        $this->assertSame(1, GrantedAccess::query()->count());
    }

    /** RNF-004: the confirmation preserves who recorded it and when. */
    public function test_the_confirmation_preserves_its_actor_and_instant(): void
    {
        [$grantedAccess, $owner] = $this->grantedStandardAccess();

        $confirmation = $this->action()->execute($grantedAccess->id, $owner->id);
        $persisted = RevocationConfirmation::query()->findOrFail($confirmation->id);

        $this->assertSame($owner->id, $persisted->actor_reference_id);
        $this->assertSame($owner->id, $persisted->actorReference->id);
        $this->assertSame($grantedAccess->id, $persisted->grantedAccess->id);
        $this->assertTrue($persisted->recorded_at->equalTo($confirmation->recorded_at));
    }

    public function test_an_unknown_granted_access_uses_the_normal_lookup_failure(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->action()->execute((string) Str::uuid7(), $this->makeActor('Owner')->id);
    }

    /**
     * Builds a Standard access through the real Actions, ending open-ended.
     *
     * @return array{0: GrantedAccess, 1: ActorReference, 2: AccessRequest}
     */
    private function grantedStandardAccess(): array
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($requester, $profile, 'S1');
        $this->approveUntilAwaitingGrant($request, $owner);
        (new ConfirmExternalAccessGrant())->execute($request->id, $owner->id);

        return [GrantedAccess::query()->firstOrFail(), $owner, $request];
    }

    /**
     * Builds a Privileged access through the real Actions.
     *
     * @return array{0: GrantedAccess, 1: ActorReference, 2: AccessRequest}
     */
    private function grantedPrivilegedAccess(int $durationSeconds): array
    {
        $owner = $this->makeActor('Owner');
        $governance = $this->makeActor('Governance');
        $this->makeGovernanceMembership($governance);
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');
        $request = $this->makeAccessRequest($requester, $profile, 'S1', $durationSeconds);
        $this->approveUntilAwaitingGrant($request, $owner, $governance);
        (new ConfirmExternalAccessGrant())->execute($request->id, $owner->id);

        return [GrantedAccess::query()->firstOrFail(), $owner, $request];
    }

    private function assertViolation(callable $operation): RevocationConfirmationViolation
    {
        try {
            $operation();
        } catch (RevocationConfirmationViolation $violation) {
            return $violation;
        }

        $this->fail('Expected a RevocationConfirmationViolation.');
    }
}
