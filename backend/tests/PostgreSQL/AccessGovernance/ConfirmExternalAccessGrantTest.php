<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Actions\ConfirmExternalAccessGrant;
use App\AccessGovernance\Exceptions\GrantConfirmationViolation;
use App\Models\AccessRequest;
use App\Models\ActorReference;
use App\Models\Decision;
use App\Models\GrantConfirmation;
use App\Models\GrantedAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * UC-003 / RF-006 — recording that the grant was executed externally.
 */
final class ConfirmExternalAccessGrantTest extends PostgresTestCase
{
    private function action(): ConfirmExternalAccessGrant
    {
        return new ConfirmExternalAccessGrant();
    }

    /** CA-016: the Standard flow reaches S4 with a Granted Access and no end date. */
    public function test_a_standard_request_is_confirmed_and_creates_an_open_ended_access(): void
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($requester, $profile, 'S1');
        $this->approveUntilAwaitingGrant($request, $owner);
        $this->assertSame('S3', $request->fresh()->current_state);

        $confirmation = $this->action()->execute($request->id, $owner->id);

        $this->assertSame('S4', $request->fresh()->current_state);
        $this->assertSame(1, GrantConfirmation::query()->count());
        $this->assertSame($request->id, $confirmation->access_request_id);
        $this->assertSame($owner->id, $confirmation->actor_reference_id);
        $this->assertNotNull($confirmation->recorded_at);

        $grantedAccess = GrantedAccess::query()->firstOrFail();
        $this->assertSame(1, GrantedAccess::query()->count());
        $this->assertSame($confirmation->id, $grantedAccess->grant_confirmation_id);
        $this->assertNull($grantedAccess->valid_until_at);

        // A1 is derived, never persisted.
        $columns = array_keys($grantedAccess->getAttributes());
        $this->assertSame(['id', 'grant_confirmation_id', 'valid_until_at'], $columns);
    }

    /** CA-016 and RN07: the privileged validity starts at the confirmation. */
    #[DataProvider('privilegedDurations')]
    public function test_a_privileged_request_is_confirmed_with_the_requested_duration(int $duration): void
    {
        $owner = $this->makeActor('Owner');
        $governance = $this->makeActor('Governance');
        $this->makeGovernanceMembership($governance);
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');
        $request = $this->makeAccessRequest($requester, $profile, 'S1', $duration);
        $this->approveUntilAwaitingGrant($request, $owner, $governance);
        $this->assertSame('S3', $request->fresh()->current_state);

        $confirmation = $this->action()->execute($request->id, $owner->id);
        $grantedAccess = GrantedAccess::query()->firstOrFail();

        $this->assertSame('S4', $request->fresh()->current_state);
        $this->assertNotNull($grantedAccess->valid_until_at);
        $this->assertSame(
            $duration,
            $grantedAccess->valid_until_at->getTimestamp() - $confirmation->recorded_at->getTimestamp()
        );
        $this->assertTrue(
            $grantedAccess->valid_until_at->equalTo($confirmation->recorded_at->addSeconds($duration))
        );
    }

    /** @return array<string, array{int}> */
    public static function privilegedDurations(): array
    {
        return ['lower bound' => [1], 'one hour' => [3600], 'upper bound' => [7776000]];
    }

    /**
     * CA-014: time spent in S3 does not consume validity. The request was made
     * and approved in the past; the validity still starts at the confirmation.
     */
    public function test_time_awaiting_the_grant_does_not_consume_the_validity(): void
    {
        $duration = 3600;
        $owner = $this->makeActor('Owner');
        $governance = $this->makeActor('Governance');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');

        $request = $this->makeAccessRequest($requester, $profile, 'S3', $duration);
        $request->requested_at = Carbon::now()->subDays(10);
        $request->save();
        $this->makeDecision($request, $owner, 'resource_owner', 'approved', null, Carbon::now()->subDays(9));
        $this->makeDecision($request, $governance, 'governance', 'approved', null, Carbon::now()->subDays(8));

        // Nothing exists before the confirmation.
        $this->assertSame(0, GrantConfirmation::query()->count());
        $this->assertSame(0, GrantedAccess::query()->count());
        $this->assertSame('S3', $request->fresh()->current_state);

        $confirmation = $this->action()->execute($request->id, $owner->id);
        $grantedAccess = GrantedAccess::query()->firstOrFail();

        $this->assertSame(
            $duration,
            $grantedAccess->valid_until_at->getTimestamp() - $confirmation->recorded_at->getTimestamp()
        );
        $this->assertGreaterThan(
            $request->fresh()->requested_at->getTimestamp(),
            $confirmation->recorded_at->getTimestamp()
        );
        $this->assertLessThan(60, Carbon::now()->getTimestamp() - $confirmation->recorded_at->getTimestamp());
    }

    /** The approvals stay untouched: approval is not the grant. */
    public function test_the_previous_decisions_are_preserved_by_the_confirmation(): void
    {
        $owner = $this->makeActor('Owner');
        $governance = $this->makeActor('Governance');
        $this->makeGovernanceMembership($governance);
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S1', 600);
        $this->approveUntilAwaitingGrant($request, $owner, $governance);

        $before = Decision::query()->orderBy('stage')->get()
            ->map(fn (Decision $decision): array => [
                $decision->id, $decision->stage, $decision->outcome,
                $decision->actor_reference_id, $decision->decided_at->getTimestamp(),
            ])->all();

        $this->action()->execute($request->id, $owner->id);

        $after = Decision::query()->orderBy('stage')->get()
            ->map(fn (Decision $decision): array => [
                $decision->id, $decision->stage, $decision->outcome,
                $decision->actor_reference_id, $decision->decided_at->getTimestamp(),
            ])->all();

        $this->assertSame($before, $after);
        $this->assertCount(2, $after);
    }

    /** CA-015 / RN10: the request must already be awaiting the grant. */
    #[DataProvider('statesThatCannotBeConfirmed')]
    public function test_a_request_outside_s3_cannot_be_confirmed(string $state): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, $state);
        $this->makeDecision($request, $owner, 'resource_owner');

        $violation = $this->assertViolation(fn () => $this->action()->execute($request->id, $owner->id));

        $this->assertSame('RN10', $violation->constraintId);
        $this->assertNoGrantFacts();
        $this->assertSame($state, $request->fresh()->current_state);
    }

    /** @return array<string, array{string}> */
    public static function statesThatCannotBeConfirmed(): array
    {
        return [
            'awaiting the resource owner' => ['S1'],
            'awaiting governance' => ['S2'],
            'already rejected' => ['S5'],
        ];
    }

    /**
     * CA-015 / RN10: the state alone is not proof. This fixture is deliberately
     * inconsistent — S3 without the required approvals.
     */
    public function test_a_request_in_s3_without_the_required_approvals_cannot_be_confirmed(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S3');

        $violation = $this->assertViolation(fn () => $this->action()->execute($request->id, $owner->id));

        $this->assertSame('RN10', $violation->constraintId);
        $this->assertNoGrantFacts();
        $this->assertSame('S3', $request->fresh()->current_state);
    }

    /** CA-015 / RN10: a privileged request still lacking the Governance approval. */
    public function test_a_privileged_request_without_the_governance_approval_cannot_be_confirmed(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S3', 1200);
        $this->makeDecision($request, $owner, 'resource_owner');

        $violation = $this->assertViolation(fn () => $this->action()->execute($request->id, $owner->id));

        $this->assertSame('RN10', $violation->constraintId);
        $this->assertNoGrantFacts();
    }

    /** RN10: a rejected step is not an approval. */
    public function test_a_request_whose_step_was_rejected_cannot_be_confirmed(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S3');
        $this->makeDecision($request, $owner, 'resource_owner', 'rejected', 'Not needed.');

        $violation = $this->assertViolation(fn () => $this->action()->execute($request->id, $owner->id));

        $this->assertSame('RN10', $violation->constraintId);
        $this->assertNoGrantFacts();
    }

    /** RF-006: only the Resource Owner of the profile's resource may confirm. */
    public function test_an_unrelated_actor_cannot_confirm_the_grant(): void
    {
        $owner = $this->makeActor('Owner');
        $stranger = $this->makeActor('Stranger');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S1');
        $this->approveUntilAwaitingGrant($request, $owner);

        $violation = $this->assertViolation(fn () => $this->action()->execute($request->id, $stranger->id));

        $this->assertSame('RF-006', $violation->constraintId);
        $this->assertNoGrantFacts();
        $this->assertSame('S3', $request->fresh()->current_state);
    }

    /** RF-006: Governance authority does not authorize the confirmation. */
    public function test_a_governance_member_who_is_not_the_owner_cannot_confirm_the_grant(): void
    {
        $owner = $this->makeActor('Owner');
        $governance = $this->makeActor('Governance');
        $this->makeGovernanceMembership($governance);
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S1', 900);
        $this->approveUntilAwaitingGrant($request, $owner, $governance);

        $violation = $this->assertViolation(fn () => $this->action()->execute($request->id, $governance->id));

        $this->assertSame('RF-006', $violation->constraintId);
        $this->assertNoGrantFacts();
        $this->assertSame('S3', $request->fresh()->current_state);
    }

    /** An actor holding both authorities confirms as Resource Owner. */
    public function test_an_owner_who_is_also_a_governance_member_can_confirm(): void
    {
        $ownerAndGovernance = $this->makeActor('Owner and Governance');
        $this->makeGovernanceMembership($ownerAndGovernance);
        $profile = $this->makeProfile($this->makeResource($ownerAndGovernance), 'privileged');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S1', 60);
        $this->approveUntilAwaitingGrant($request, $ownerAndGovernance, $ownerAndGovernance);

        $confirmation = $this->action()->execute($request->id, $ownerAndGovernance->id);

        $this->assertSame($ownerAndGovernance->id, $confirmation->actor_reference_id);
        $this->assertSame('S4', $request->fresh()->current_state);
    }

    /** Repeating a completed confirmation produces no second fact (RNF-009). */
    public function test_repeating_the_confirmation_does_not_create_a_second_fact(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S1');
        $this->approveUntilAwaitingGrant($request, $owner);

        $this->action()->execute($request->id, $owner->id);
        $violation = $this->assertViolation(fn () => $this->action()->execute($request->id, $owner->id));

        $this->assertSame('RN10', $violation->constraintId);
        $this->assertSame(1, GrantConfirmation::query()->count());
        $this->assertSame(1, GrantedAccess::query()->count());
        $this->assertSame('S4', $request->fresh()->current_state);
    }

    public function test_an_unknown_request_uses_the_normal_lookup_failure(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->action()->execute((string) Str::uuid7(), $this->makeActor('Owner')->id);
    }

    private function assertNoGrantFacts(): void
    {
        $this->assertSame(0, GrantConfirmation::query()->count());
        $this->assertSame(0, GrantedAccess::query()->count());
    }

    private function assertViolation(callable $operation): GrantConfirmationViolation
    {
        try {
            $operation();
        } catch (GrantConfirmationViolation $violation) {
            return $violation;
        }

        $this->fail('Expected a GrantConfirmationViolation.');
    }
}
