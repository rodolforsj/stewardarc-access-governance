<?php

declare(strict_types=1);

namespace App\AccessGovernance\Actions;

use App\AccessGovernance\Concurrency\FunctionalTransactionTime;
use App\AccessGovernance\Concurrency\Rn03AdvisoryLock;
use App\AccessGovernance\Exceptions\AccessRequestRuleViolation;
use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\GrantedAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * UC-001 / RF-002 — register an access request for the requester themselves.
 */
final class CreateAccessRequest
{
    private const PROCESSING_STATES = ['S1', 'S2', 'S3'];

    private const INITIAL_STATE = 'S1';

    private const MAX_PRIVILEGED_DURATION_SECONDS = 7776000;

    public function __construct(
        private readonly Rn03AdvisoryLock $rn03Lock = new Rn03AdvisoryLock(),
        private readonly FunctionalTransactionTime $transactionTime = new FunctionalTransactionTime(),
    ) {
    }

    /**
     * The requester is resolved by the caller. There is no target actor
     * parameter, so a request is always for the requester themselves (RN02).
     */
    public function execute(
        string $requesterActorReferenceId,
        string $accessProfileId,
        string $justification,
        ?int $requestedDurationSeconds = null,
    ): AccessRequest {
        return DB::transaction(function () use (
            $requesterActorReferenceId,
            $accessProfileId,
            $justification,
            $requestedDurationSeconds,
        ): AccessRequest {
            $this->rn03Lock->acquire($requesterActorReferenceId, $accessProfileId);

            $profile = AccessProfile::query()->with('resource')->findOrFail($accessProfileId);

            if (! $profile->is_available) {
                throw new AccessRequestRuleViolation('RN01', 'The access profile is not available for new requests.');
            }

            if ($profile->resource->resource_owner_actor_reference_id === $requesterActorReferenceId) {
                throw new AccessRequestRuleViolation(
                    'RN11',
                    'A Resource Owner cannot request a profile of the resource they are responsible for.'
                );
            }

            $approvalFlow = (string) $profile->classification;
            $duration = $this->resolveDuration($approvalFlow, $requestedDurationSeconds);

            $this->guardEquivalentRequestInProcessing($requesterActorReferenceId, $accessProfileId);

            // ADR-009: one PostgreSQL instant for the whole operation, used both
            // to evaluate the active access and as the registration instant.
            $functionalTransactionAt = $this->transactionTime->current();

            $this->guardEquivalentActiveAccess($requesterActorReferenceId, $accessProfileId, $functionalTransactionAt);

            $request = new AccessRequest();
            $request->requester_actor_reference_id = $requesterActorReferenceId;
            $request->access_profile_id = $profile->id;
            $request->justification = $justification;
            $request->approval_flow = $approvalFlow;
            $request->requested_duration_seconds = $duration;
            $request->current_state = self::INITIAL_STATE;
            $request->requested_at = $functionalTransactionAt;
            $request->save();

            return $request;
        });
    }

    /**
     * RN07 for privileged profiles. A duration sent for a standard profile is a
     * violation of this Action's contract, not a business rule.
     */
    private function resolveDuration(string $approvalFlow, ?int $requestedDurationSeconds): ?int
    {
        if ($approvalFlow !== 'privileged') {
            if ($requestedDurationSeconds !== null) {
                throw new InvalidArgumentException(
                    'A requested duration is only accepted for privileged access profiles.'
                );
            }

            return null;
        }

        if ($requestedDurationSeconds === null
            || $requestedDurationSeconds <= 0
            || $requestedDurationSeconds > self::MAX_PRIVILEGED_DURATION_SECONDS) {
            throw new AccessRequestRuleViolation(
                'RN07',
                'A privileged request requires a duration greater than zero and at most 90 days.'
            );
        }

        return $requestedDurationSeconds;
    }

    private function guardEquivalentRequestInProcessing(string $requesterId, string $profileId): void
    {
        $exists = AccessRequest::query()
            ->where('requester_actor_reference_id', $requesterId)
            ->where('access_profile_id', $profileId)
            ->whereIn('current_state', self::PROCESSING_STATES)
            ->exists();

        if ($exists) {
            throw new AccessRequestRuleViolation(
                'RN03',
                'An equivalent access request is already in processing.'
            );
        }
    }

    /**
     * An equivalent Granted Access counts as A1 only when no revocation was
     * confirmed and the validity period has not ended at the operation's
     * instant: a validity end equal to it has already ended. A2 and A3 do not
     * block.
     */
    private function guardEquivalentActiveAccess(
        string $requesterId,
        string $profileId,
        CarbonImmutable $functionalTransactionAt,
    ): void
    {
        $exists = GrantedAccess::query()
            ->join('grant_confirmations', 'grant_confirmations.id', '=', 'granted_accesses.grant_confirmation_id')
            ->join('access_requests', 'access_requests.id', '=', 'grant_confirmations.access_request_id')
            ->where('access_requests.requester_actor_reference_id', $requesterId)
            ->where('access_requests.access_profile_id', $profileId)
            ->whereNotExists(function (Builder $query): void {
                $query->select(DB::raw('1'))
                    ->from('revocation_confirmations')
                    ->whereColumn('revocation_confirmations.granted_access_id', 'granted_accesses.id');
            })
            ->where(function (\Illuminate\Contracts\Database\Query\Builder $query) use ($functionalTransactionAt): void {
                // Bound with microseconds and offset, so the comparison uses the
                // exact transaction instant rather than a value truncated to seconds.
                $query->whereNull('granted_accesses.valid_until_at')
                    ->orWhere('granted_accesses.valid_until_at', '>', $functionalTransactionAt->format('Y-m-d H:i:s.uP'));
            })
            ->exists();

        if ($exists) {
            throw new AccessRequestRuleViolation(
                'RN03',
                'An equivalent access is currently active.'
            );
        }
    }
}
