<?php

declare(strict_types=1);

namespace App\AccessGovernance\Actions;

use App\AccessGovernance\Concurrency\FunctionalTransactionTime;
use App\AccessGovernance\Concurrency\Rn03AdvisoryLock;
use App\AccessGovernance\Exceptions\RevocationConfirmationViolation;
use App\Models\GrantedAccess;
use App\Models\RevocationConfirmation;
use App\Observability\OperationContext;
use Illuminate\Support\Facades\DB;

/**
 * UC-004 / RF-007 — the Resource Owner records that a previously granted
 * access was revoked in the external system. StewardArc preserves the fact; it
 * performs no deprovisioning and persists no A1/A2/A3 state.
 */
final class RecordExternalAccessRevocation
{
    /** This operation in operational records (ADR-012). */
    private const OPERATION = 'access_revocation.record';

    public function __construct(
        private readonly Rn03AdvisoryLock $rn03Lock = new Rn03AdvisoryLock(),
        private readonly FunctionalTransactionTime $transactionTime = new FunctionalTransactionTime(),
    ) {
    }

    /**
     * $actorReferenceId is the actor recording the confirmation in StewardArc,
     * not necessarily whoever executed the revocation externally.
     */
    public function execute(string $grantedAccessId, string $actorReferenceId): RevocationConfirmation
    {
        return OperationContext::run(self::OPERATION, fn (): RevocationConfirmation => DB::transaction(function () use (
            $grantedAccessId,
            $actorReferenceId,
        ): RevocationConfirmation {
            // Structural pre-read, used only to derive the RN03 advisory key:
            // Granted Access → Grant Confirmation → Access Request.
            $structural = GrantedAccess::query()->findOrFail($grantedAccessId);
            $origin = $this->originOf($structural->grant_confirmation_id);

            // ADR-006 lock order: advisory lock first, then the row lock.
            $this->rn03Lock->acquire(
                (string) $origin->requester_actor_reference_id,
                (string) $origin->access_profile_id,
            );

            $grantedAccess = GrantedAccess::query()
                ->lockForUpdate()
                ->findOrFail($grantedAccessId);

            // Everything below is validated after the row lock.
            $alreadyConfirmed = RevocationConfirmation::query()
                ->where('granted_access_id', $grantedAccess->id)
                ->exists();

            if ($alreadyConfirmed) {
                throw new RevocationConfirmationViolation(
                    'RF-007',
                    'This access already has a recorded revocation confirmation.'
                );
            }

            $this->guardResourceOwnerAuthority($grantedAccess, $actorReferenceId);

            // ADR-009: the PostgreSQL instant of this transaction, read once.
            $functionalTransactionAt = $this->transactionTime->current();

            $confirmation = new RevocationConfirmation();
            $confirmation->granted_access_id = $grantedAccess->id;
            $confirmation->actor_reference_id = $actorReferenceId;
            $confirmation->recorded_at = $functionalTransactionAt;
            $confirmation->save();

            return $confirmation;
        }));
    }

    /**
     * The requester and the access profile of the originating request, which
     * are structural facts and are not changed by the MVP operations.
     */
    private function originOf(string $grantConfirmationId): object
    {
        return DB::table('grant_confirmations')
            ->join('access_requests', 'access_requests.id', '=', 'grant_confirmations.access_request_id')
            ->where('grant_confirmations.id', $grantConfirmationId)
            ->select([
                'access_requests.requester_actor_reference_id',
                'access_requests.access_profile_id',
            ])
            ->firstOrFail();
    }

    /**
     * RF-007: only the current Resource Owner of the originating profile's
     * resource records the confirmation. Governance authority does not apply.
     */
    private function guardResourceOwnerAuthority(GrantedAccess $grantedAccess, string $actorReferenceId): void
    {
        $resourceOwnerId = DB::table('granted_accesses')
            ->join('grant_confirmations', 'grant_confirmations.id', '=', 'granted_accesses.grant_confirmation_id')
            ->join('access_requests', 'access_requests.id', '=', 'grant_confirmations.access_request_id')
            ->join('access_profiles', 'access_profiles.id', '=', 'access_requests.access_profile_id')
            ->join('resources', 'resources.id', '=', 'access_profiles.resource_id')
            ->where('granted_accesses.id', $grantedAccess->id)
            ->value('resources.resource_owner_actor_reference_id');

        if ($resourceOwnerId !== $actorReferenceId) {
            throw new RevocationConfirmationViolation(
                'RF-007',
                'Only the Resource Owner of the originating profile can record the revocation confirmation.'
            );
        }
    }
}
