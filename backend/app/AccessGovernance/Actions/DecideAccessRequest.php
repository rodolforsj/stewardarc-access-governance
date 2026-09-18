<?php

declare(strict_types=1);

namespace App\AccessGovernance\Actions;

use App\AccessGovernance\Concurrency\FunctionalTransactionTime;
use App\AccessGovernance\Exceptions\AccessDecisionRuleViolation;
use App\Models\AccessRequest;
use App\Models\Decision;
use App\Models\GovernanceMembership;
use App\Observability\OperationContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * UC-002 / RF-005 — record the decision of the step currently pending on an
 * access request and apply the resulting lifecycle transition atomically.
 */
final class DecideAccessRequest
{
    /** This operation in operational records (ADR-012). */
    private const OPERATION = 'access_request.decide';

    private const OUTCOMES = ['approved', 'rejected'];

    private const STAGE_BY_STATE = [
        'S1' => 'resource_owner',
        'S2' => 'governance',
    ];

    public function __construct(
        private readonly FunctionalTransactionTime $transactionTime = new FunctionalTransactionTime(),
    ) {
    }

    /**
     * The stage is never chosen by the caller: it is inferred from the current
     * state of the request, after the row lock.
     */
    public function execute(
        string $accessRequestId,
        string $actorReferenceId,
        string $outcome,
        ?string $justification = null,
    ): Decision {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('The outcome must be either approved or rejected.');
        }

        return OperationContext::run(self::OPERATION, fn (): Decision => DB::transaction(function () use (
            $accessRequestId,
            $actorReferenceId,
            $outcome,
            $justification,
        ): Decision {
            $request = AccessRequest::query()
                ->lockForUpdate()
                ->findOrFail($accessRequestId);

            $stage = self::STAGE_BY_STATE[$request->current_state] ?? null;

            if ($stage === null) {
                throw new AccessDecisionRuleViolation(
                    'RN09',
                    'The request has no approval step pending a decision.'
                );
            }

            if ($request->requester_actor_reference_id === $actorReferenceId) {
                throw new AccessDecisionRuleViolation(
                    'RN04',
                    'Nobody can decide their own access request.'
                );
            }

            $this->guardCurrentAuthority($request, $actorReferenceId, $stage);

            if ($outcome === 'rejected' && ($justification === null || trim($justification) === '')) {
                throw new AccessDecisionRuleViolation(
                    'RN08',
                    'A rejection requires a justification.'
                );
            }

            // ADR-009: the PostgreSQL instant of this transaction, read once.
            $functionalTransactionAt = $this->transactionTime->current();

            $decision = new Decision();
            $decision->access_request_id = $request->id;
            $decision->stage = $stage;
            $decision->outcome = $outcome;
            $decision->actor_reference_id = $actorReferenceId;
            $decision->justification = $justification;
            $decision->decided_at = $functionalTransactionAt;
            $decision->save();

            $request->current_state = $this->nextState($request, $stage, $outcome);
            $request->save();

            return $decision;
        }));
    }

    /**
     * RN09: only the authority of the step currently pending may decide.
     */
    private function guardCurrentAuthority(AccessRequest $request, string $actorReferenceId, string $stage): void
    {
        if ($stage === 'resource_owner') {
            $resourceOwnerId = DB::table('access_profiles')
                ->join('resources', 'resources.id', '=', 'access_profiles.resource_id')
                ->where('access_profiles.id', $request->access_profile_id)
                ->value('resources.resource_owner_actor_reference_id');

            if ($resourceOwnerId !== $actorReferenceId) {
                throw new AccessDecisionRuleViolation(
                    'RN09',
                    'Only the Resource Owner of the requested profile can decide this step.'
                );
            }

            return;
        }

        $holdsGovernanceAuthority = GovernanceMembership::query()
            ->whereKey($actorReferenceId)
            ->exists();

        if (! $holdsGovernanceAuthority) {
            throw new AccessDecisionRuleViolation(
                'RN09',
                'Only an actor with current Governance authority can decide this step.'
            );
        }
    }

    /**
     * RN05 and RN06, using the approval flow snapshot of the request.
     */
    private function nextState(AccessRequest $request, string $stage, string $outcome): string
    {
        if ($outcome === 'rejected') {
            return 'S5';
        }

        if ($stage === 'governance') {
            return 'S3';
        }

        return $request->approval_flow === 'privileged' ? 'S2' : 'S3';
    }
}
