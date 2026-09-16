<?php

declare(strict_types=1);

namespace App\AccessGovernance\Actions;

use App\AccessGovernance\Concurrency\Rn03AdvisoryLock;
use App\AccessGovernance\Exceptions\GrantConfirmationViolation;
use App\Models\AccessRequest;
use App\Models\Decision;
use App\Models\GrantConfirmation;
use App\Models\GrantedAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * UC-003 / RF-006 — the Resource Owner records that the grant was executed
 * externally. StewardArc does not provision access: this operation only
 * registers the fact, concludes the request and creates the Granted Access.
 */
final class ConfirmExternalAccessGrant
{
    private const AWAITING_GRANT = 'S3';

    private const GRANT_CONFIRMED = 'S4';

    private const REQUIRED_APPROVALS = [
        'standard' => ['resource_owner'],
        'privileged' => ['resource_owner', 'governance'],
    ];

    public function __construct(
        private readonly Rn03AdvisoryLock $rn03Lock = new Rn03AdvisoryLock(),
    ) {
    }

    /**
     * $actorReferenceId is the actor recording the confirmation in StewardArc,
     * not necessarily whoever executed the grant in the external system.
     */
    public function execute(string $accessRequestId, string $actorReferenceId): GrantConfirmation
    {
        return DB::transaction(function () use ($accessRequestId, $actorReferenceId): GrantConfirmation {
            // Structural pre-read, used only to derive the RN03 advisory key.
            // The requester and the profile of a request are not changed by the
            // functional operations of the MVP (ADR-006).
            $structural = AccessRequest::query()
                ->select(['id', 'requester_actor_reference_id', 'access_profile_id'])
                ->findOrFail($accessRequestId);

            // ADR-006 lock order: advisory lock first, then the row lock.
            $this->rn03Lock->acquire(
                (string) $structural->requester_actor_reference_id,
                (string) $structural->access_profile_id,
            );

            $request = AccessRequest::query()
                ->lockForUpdate()
                ->findOrFail($accessRequestId);

            // Everything below is validated from the locked row.
            if ($request->current_state !== self::AWAITING_GRANT) {
                throw new GrantConfirmationViolation(
                    'RN10',
                    'A grant can only be confirmed while the request is awaiting the grant.'
                );
            }

            $this->guardApprovalsAreComplete($request);
            $this->guardResourceOwnerAuthority($request, $actorReferenceId);

            // One single instant for the whole functional result.
            $recordedAt = Carbon::now();

            $confirmation = new GrantConfirmation();
            $confirmation->access_request_id = $request->id;
            $confirmation->actor_reference_id = $actorReferenceId;
            $confirmation->recorded_at = $recordedAt;
            $confirmation->save();

            $grantedAccess = new GrantedAccess();
            $grantedAccess->grant_confirmation_id = $confirmation->id;
            $grantedAccess->valid_until_at = $this->effectiveValidityEnd($request, $recordedAt);
            $grantedAccess->save();

            $request->current_state = self::GRANT_CONFIRMED;
            $request->save();

            return $confirmation;
        });
    }

    /**
     * RN10: the preserved Decision facts must show every required approval, not
     * only the current state.
     */
    private function guardApprovalsAreComplete(AccessRequest $request): void
    {
        $approvedStages = Decision::query()
            ->where('access_request_id', $request->id)
            ->where('outcome', 'approved')
            ->pluck('stage')
            ->all();

        $requiredStages = self::REQUIRED_APPROVALS[(string) $request->approval_flow] ?? [];

        foreach ($requiredStages as $stage) {
            if (! in_array($stage, $approvedStages, true)) {
                throw new GrantConfirmationViolation(
                    'RN10',
                    'A grant can only be confirmed after all required approvals are recorded.'
                );
            }
        }
    }

    /**
     * RF-006: only the current Resource Owner of the requested profile's
     * resource records the confirmation. Governance authority does not apply.
     */
    private function guardResourceOwnerAuthority(AccessRequest $request, string $actorReferenceId): void
    {
        $resourceOwnerId = DB::table('access_profiles')
            ->join('resources', 'resources.id', '=', 'access_profiles.resource_id')
            ->where('access_profiles.id', $request->access_profile_id)
            ->value('resources.resource_owner_actor_reference_id');

        if ($resourceOwnerId !== $actorReferenceId) {
            throw new GrantConfirmationViolation(
                'RF-006',
                'Only the Resource Owner of the requested profile can record the grant confirmation.'
            );
        }
    }

    /**
     * RN07: the effective validity starts at the confirmation instant. Standard
     * access has no calculable end.
     */
    private function effectiveValidityEnd(AccessRequest $request, Carbon $recordedAt): ?Carbon
    {
        if ($request->approval_flow !== 'privileged') {
            return null;
        }

        return $recordedAt->copy()->addSeconds((int) $request->requested_duration_seconds);
    }
}
