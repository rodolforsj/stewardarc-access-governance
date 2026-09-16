<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\AccessGovernance;

use App\AccessGovernance\Actions\DecideAccessRequest;
use App\AccessGovernance\Exceptions\AccessDecisionRuleViolation;
use App\Models\AccessRequest;
use App\Models\Decision;
use App\Models\GrantedAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * UC-002 / RF-005 — recording the decision of the pending step.
 */
final class DecideAccessRequestTest extends PostgresTestCase
{
    private function action(): DecideAccessRequest
    {
        return new DecideAccessRequest();
    }

    /** CA-009: approval in the Standard flow moves the request from S1 to S3. */
    public function test_a_resource_owner_approval_moves_a_standard_request_to_s3(): void
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($requester, $profile, 'S1');

        $decision = $this->action()->execute($request->id, $owner->id, 'approved');

        $this->assertSame('resource_owner', $decision->stage);
        $this->assertSame('approved', $decision->outcome);
        $this->assertSame($owner->id, $decision->actor_reference_id);
        $this->assertNotNull($decision->decided_at);
        $this->assertNull($decision->justification);

        $this->assertSame('S3', $request->fresh()->current_state);
        $this->assertSame(1, Decision::query()->count());
        $this->assertSame(0, GrantedAccess::query()->count());
    }

    /** CA-010: the Privileged flow follows S1 → S2. */
    public function test_a_resource_owner_approval_moves_a_privileged_request_to_s2(): void
    {
        $owner = $this->makeActor('Owner');
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');
        $request = $this->makeAccessRequest($requester, $profile, 'S1');

        $decision = $this->action()->execute($request->id, $owner->id, 'approved');

        $this->assertSame('resource_owner', $decision->stage);
        $this->assertSame('S2', $request->fresh()->current_state);
        $this->assertSame(1, Decision::query()->count());
    }

    /** CA-010: the Privileged flow completes S2 → S3 with the Governance step. */
    public function test_a_governance_approval_moves_a_privileged_request_to_s3(): void
    {
        $owner = $this->makeActor('Owner');
        $governance = $this->makeActor('Governance');
        $this->makeGovernanceMembership($governance);
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');
        $request = $this->makeAccessRequest($requester, $profile, 'S1');

        $this->action()->execute($request->id, $owner->id, 'approved');
        $governanceDecision = $this->action()->execute($request->id, $governance->id, 'approved');

        $this->assertSame('governance', $governanceDecision->stage);
        $this->assertSame('S3', $request->fresh()->current_state);

        $stages = Decision::query()->where('access_request_id', $request->id)->pluck('stage')->sort()->values()->all();
        $this->assertSame(['governance', 'resource_owner'], $stages);
        $this->assertSame(0, GrantedAccess::query()->count());
    }

    /** The baseline has no separation of duties between the two authorities. */
    public function test_the_same_actor_may_decide_both_steps_when_not_the_requester(): void
    {
        $ownerAndGovernance = $this->makeActor('Owner and Governance');
        $this->makeGovernanceMembership($ownerAndGovernance);
        $requester = $this->makeActor('Requester');
        $profile = $this->makeProfile($this->makeResource($ownerAndGovernance), 'privileged');
        $request = $this->makeAccessRequest($requester, $profile, 'S1');

        $first = $this->action()->execute($request->id, $ownerAndGovernance->id, 'approved');
        $second = $this->action()->execute($request->id, $ownerAndGovernance->id, 'approved');

        $this->assertSame('resource_owner', $first->stage);
        $this->assertSame('governance', $second->stage);
        $this->assertSame('S3', $request->fresh()->current_state);
        $this->assertSame(2, Decision::query()->count());
    }

    /** CA-013: a justified rejection ends the request in S5. */
    #[DataProvider('rejectionSteps')]
    public function test_a_justified_rejection_ends_the_request_in_s5(string $state, string $expectedStage): void
    {
        [$request, $owner, $governance] = $this->privilegedRequestIn($state);
        $decidingActor = $expectedStage === 'resource_owner' ? $owner : $governance;

        $decision = $this->action()->execute($request->id, $decidingActor->id, 'rejected', 'Not justified by the role.');

        $this->assertSame($expectedStage, $decision->stage);
        $this->assertSame('rejected', $decision->outcome);
        $this->assertSame('Not justified by the role.', $decision->justification);
        $this->assertSame('S5', $request->fresh()->current_state);
    }

    /** @return array<string, array{string, string}> */
    public static function rejectionSteps(): array
    {
        return [
            'resource owner step' => ['S1', 'resource_owner'],
            'governance step' => ['S2', 'governance'],
        ];
    }

    /** A valid justification is persisted exactly as received. */
    public function test_a_valid_justification_is_persisted_without_normalization(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S1');

        $decision = $this->action()->execute($request->id, $owner->id, 'rejected', '  spaced reason  ');

        $this->assertSame('  spaced reason  ', Decision::query()->findOrFail($decision->id)->justification);
    }

    /** An approval may carry a justification; the baseline does not forbid it. */
    public function test_an_approval_keeps_the_justification_when_one_is_given(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S1');

        $decision = $this->action()->execute($request->id, $owner->id, 'approved', 'Needed for the migration project.');

        $this->assertSame('Needed for the migration project.', $decision->fresh()->justification);
        $this->assertSame('S3', $request->fresh()->current_state);
    }

    /** CA-013 / RN08: a rejection without a usable justification is refused. */
    #[DataProvider('blankJustifications')]
    public function test_a_rejection_without_justification_is_refused(?string $justification): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S1');

        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($request->id, $owner->id, 'rejected', $justification)
        );

        $this->assertSame('RN08', $violation->ruleId);
        $this->assertSame(0, Decision::query()->count());
        $this->assertSame('S1', $request->fresh()->current_state);
    }

    /** @return array<string, array{string|null}> */
    public static function blankJustifications(): array
    {
        return ['missing' => [null], 'empty' => [''], 'whitespace only' => ["  \t \n "]];
    }

    /** CA-011 / RN04: a Governance member cannot decide their own request. */
    public function test_a_governance_member_cannot_decide_their_own_request(): void
    {
        $governanceRequester = $this->makeActor('Governance requester');
        $this->makeGovernanceMembership($governanceRequester);
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), 'privileged');
        $request = $this->makeAccessRequest($governanceRequester, $profile, 'S2');

        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($request->id, $governanceRequester->id, 'approved')
        );

        $this->assertSame('RN04', $violation->ruleId);
        $this->assertSame(0, Decision::query()->count());
        $this->assertSame('S2', $request->fresh()->current_state);
    }

    /**
     * CA-011 / RN04 at the Resource Owner step. The fixture is structural: it
     * represents a state that RN11 would prevent from arising through the
     * normal request flow.
     */
    public function test_a_resource_owner_cannot_decide_their_own_request(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($owner, $profile, 'S1');

        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($request->id, $owner->id, 'approved')
        );

        $this->assertSame('RN04', $violation->ruleId);
        $this->assertSame(0, Decision::query()->count());
        $this->assertSame('S1', $request->fresh()->current_state);
    }

    /** CA-012 / RN09: only the Resource Owner decides the S1 step. */
    public function test_a_non_owner_cannot_decide_the_resource_owner_step(): void
    {
        $owner = $this->makeActor('Owner');
        $stranger = $this->makeActor('Stranger');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S1');

        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($request->id, $stranger->id, 'approved')
        );

        $this->assertSame('RN09', $violation->ruleId);
        $this->assertSame(0, Decision::query()->count());
        $this->assertSame('S1', $request->fresh()->current_state);
    }

    /** CA-012 / RN09: Governance authority does not decide another resource's S1. */
    public function test_a_governance_member_cannot_decide_the_resource_owner_step(): void
    {
        $governance = $this->makeActor('Governance');
        $this->makeGovernanceMembership($governance);
        $profile = $this->makeProfile($this->makeResource($this->makeActor('Owner')), 'privileged');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S1');

        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($request->id, $governance->id, 'approved')
        );

        $this->assertSame('RN09', $violation->ruleId);
        $this->assertSame('S1', $request->fresh()->current_state);
    }

    /** CA-012 / RN09: the Resource Owner alone cannot decide the Governance step. */
    public function test_a_resource_owner_without_membership_cannot_decide_the_governance_step(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S2');

        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($request->id, $owner->id, 'approved')
        );

        $this->assertSame('RN09', $violation->ruleId);
        $this->assertSame(0, Decision::query()->count());
        $this->assertSame('S2', $request->fresh()->current_state);
    }

    /** CA-012 / RN09: there is no pending step outside S1 and S2. */
    #[DataProvider('statesWithoutPendingStep')]
    public function test_a_request_outside_the_decision_steps_cannot_be_decided(string $state): void
    {
        $owner = $this->makeActor('Owner');
        $governance = $this->makeActor('Governance');
        $this->makeGovernanceMembership($governance);
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, $state);

        foreach ([$owner, $governance] as $actor) {
            $violation = $this->assertRuleViolation(
                fn () => $this->action()->execute($request->id, $actor->id, 'approved')
            );
            $this->assertSame('RN09', $violation->ruleId);
        }

        $this->assertSame(0, Decision::query()->count());
        $this->assertSame($state, $request->fresh()->current_state);
    }

    /** @return array<string, array{string}> */
    public static function statesWithoutPendingStep(): array
    {
        return ['awaiting grant' => ['S3'], 'grant confirmed' => ['S4'], 'rejected' => ['S5']];
    }

    /** Repeating a completed decision produces no second fact (RNF-009). */
    public function test_repeating_the_same_decision_does_not_create_a_second_fact(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S1');

        $this->action()->execute($request->id, $owner->id, 'approved');

        $violation = $this->assertRuleViolation(
            fn () => $this->action()->execute($request->id, $owner->id, 'approved')
        );

        $this->assertSame('RN09', $violation->ruleId);
        $this->assertSame(1, Decision::query()->count());
        $this->assertSame('S3', $request->fresh()->current_state);
    }

    public function test_an_unknown_outcome_breaks_the_action_contract(): void
    {
        $owner = $this->makeActor('Owner');
        $profile = $this->makeProfile($this->makeResource($owner), 'standard');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, 'S1');

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->action()->execute($request->id, $owner->id, 'maybe');
        } finally {
            $this->assertSame(0, Decision::query()->count());
            $this->assertSame('S1', $request->fresh()->current_state);
        }
    }

    public function test_an_unknown_request_uses_the_normal_lookup_failure(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->action()->execute((string) Str::uuid7(), $this->makeActor('Owner')->id, 'approved');
    }

    /**
     * @return array{0: AccessRequest, 1: \App\Models\ActorReference, 2: \App\Models\ActorReference}
     */
    private function privilegedRequestIn(string $state): array
    {
        $owner = $this->makeActor('Owner');
        $governance = $this->makeActor('Governance');
        $this->makeGovernanceMembership($governance);
        $profile = $this->makeProfile($this->makeResource($owner), 'privileged');
        $request = $this->makeAccessRequest($this->makeActor('Requester'), $profile, $state);

        return [$request, $owner, $governance];
    }

    private function assertRuleViolation(callable $operation): AccessDecisionRuleViolation
    {
        try {
            $operation();
        } catch (AccessDecisionRuleViolation $violation) {
            return $violation;
        }

        $this->fail('Expected an AccessDecisionRuleViolation.');
    }
}
