<?php

declare(strict_types=1);

namespace App\AccessGovernance\Actions;

use App\AccessGovernance\Concurrency\ReadProjectionTransaction;
use App\Models\AccessRequest;
use App\Models\ActorReference;
use App\Models\Decision;
use App\Models\GovernanceMembership;
use App\Models\GrantConfirmation;
use App\Models\GrantedAccess;
use App\Models\RevocationConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;

/**
 * UC-006 — a Resource Owner or Governance member consults the Access Requests,
 * Granted Accesses (RF-010) and Functional History (RF-011) within the
 * responsibility scope they currently hold. RF-008 is observed through the
 * derived access state.
 *
 * The whole projection, including the scope reads, is read in one snapshot
 * (ADR-008) and returned as plain values; the shape is internal and is not an
 * API contract.
 */
final class ConsultAccessesAndHistoryWithinResponsibilityScope
{
    public function __construct(
        private readonly ReadProjectionTransaction $readTransaction = new ReadProjectionTransaction(),
    ) {
    }

    /**
     * The actor is resolved by the caller. An actor without a responsibility
     * scope gets an empty projection.
     *
     * @return array{projection_reference_at: CarbonImmutable, requests: list<array<string, mixed>>}
     */
    public function execute(string $actorReferenceId): array
    {
        return $this->readTransaction->run(
            function (CarbonImmutable $projectionReferenceAt) use ($actorReferenceId): array {
                // Current authority (ADR-007), read in the same snapshot as the content.
                $holdsGovernanceAuthority = GovernanceMembership::query()
                    ->whereKey($actorReferenceId)
                    ->exists();

                // One query for both scopes: a request in both appears once.
                // RN04 is a decision rule, so the actor's own requests are not
                // excluded from consultation.
                $requests = AccessRequest::query()
                    ->where(function (EloquentBuilder $scopes) use ($actorReferenceId, $holdsGovernanceAuthority): void {
                        $scopes->whereExists(function (QueryBuilder $query) use ($actorReferenceId): void {
                            $query->select(DB::raw('1'))
                                ->from('access_profiles')
                                ->join('resources', 'resources.id', '=', 'access_profiles.resource_id')
                                ->whereColumn('access_profiles.id', 'access_requests.access_profile_id')
                                ->where('resources.resource_owner_actor_reference_id', $actorReferenceId);
                        });

                        if ($holdsGovernanceAuthority) {
                            // The flow snapshot of the request, so a later catalog
                            // reclassification does not reinterpret history.
                            $scopes->orWhere('approval_flow', 'privileged');
                        }
                    })
                    ->with([
                        'requester:id,display_name',
                        'accessProfile:id,resource_id,name',
                        'accessProfile.resource:id,name',
                        'decisions.actorReference:id,display_name',
                        'grantConfirmation.actorReference:id,display_name',
                        'grantConfirmation.grantedAccess.revocationConfirmation.actorReference:id,display_name',
                    ])
                    ->orderBy('requested_at')
                    ->orderBy('id')
                    ->get();

                return [
                    'projection_reference_at' => $projectionReferenceAt,
                    'requests' => $requests
                        ->map(fn (AccessRequest $request): array => $this->projectRequest($request, $projectionReferenceAt))
                        ->values()
                        ->all(),
                ];
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function projectRequest(AccessRequest $request, CarbonImmutable $projectionReferenceAt): array
    {
        $profile = $request->accessProfile;
        $grantConfirmation = $request->grantConfirmation;
        $grantedAccess = $grantConfirmation?->grantedAccess;
        $revocation = $grantedAccess?->revocationConfirmation;
        $state = $grantedAccess === null ? null : self::derivedState($grantedAccess, $revocation, $projectionReferenceAt);

        return [
            'request_id' => $request->id,
            // The requester is a third party here, so it is identified explicitly.
            'requester' => self::actor($request->requester),
            'access_profile_id' => $profile->id,
            'access_profile_name' => $profile->name,
            'resource_id' => $profile->resource->id,
            'resource_name' => $profile->resource->name,
            'justification' => $request->justification,
            'approval_flow' => $request->approval_flow,
            'requested_duration_seconds' => $request->requested_duration_seconds,
            // S1–S5 is persisted and read as is.
            'current_state' => $request->current_state,
            'requested_at' => $request->requested_at,
            'granted_access' => $grantedAccess === null ? null : [
                'id' => $grantedAccess->id,
                'valid_until_at' => $grantedAccess->valid_until_at,
                'state' => $state,
            ],
            'history' => $this->history($request, $grantConfirmation, $grantedAccess, $revocation, $state),
        ];
    }

    /**
     * Functional History as a projection of the preserved facts plus the
     * derived expiration milestone, with the same semantics as UC-005.
     *
     * @return list<array<string, mixed>>
     */
    private function history(
        AccessRequest $request,
        ?GrantConfirmation $grantConfirmation,
        ?GrantedAccess $grantedAccess,
        ?RevocationConfirmation $revocation,
        ?string $state,
    ): array {
        $entries = [
            self::entry('request_registered', $request->id, $request->requested_at, $request->requester),
        ];

        foreach ($request->decisions as $decision) {
            /** @var Decision $decision */
            $entries[] = self::entry(
                'decision',
                $decision->id,
                $decision->decided_at,
                $decision->actorReference,
                $decision->stage,
                $decision->outcome,
                $decision->justification,
            );
        }

        if ($grantConfirmation !== null) {
            $entries[] = self::entry(
                'grant_confirmation',
                $grantConfirmation->id,
                $grantConfirmation->recorded_at,
                $grantConfirmation->actorReference,
            );
        }

        // Only when expiration is the effective end of the access (the A2
        // condition); an access ended earlier by revocation gets none.
        if ($state === 'A2') {
            $entries[] = self::entry('expiration', null, $grantedAccess->valid_until_at, null);
        }

        if ($revocation !== null) {
            $entries[] = self::entry(
                'revocation_confirmation',
                $revocation->id,
                $revocation->recorded_at,
                $revocation->actorReference,
            );
        }

        // Deterministic internal order only; ties carry no business meaning.
        usort(
            $entries,
            static fn (array $left, array $right): int => $left['occurred_at'] <=> $right['occurred_at']
        );

        return $entries;
    }

    /**
     * The derived Granted Access state of ADR-003 and ADR-008, evaluated against
     * the single projection reference instant. It is never persisted.
     */
    private static function derivedState(
        GrantedAccess $grantedAccess,
        ?RevocationConfirmation $revocation,
        CarbonImmutable $projectionReferenceAt,
    ): string {
        $validUntil = $grantedAccess->valid_until_at;

        if ($revocation !== null && ($validUntil === null || $revocation->recorded_at->lessThan($validUntil))) {
            return 'A3';
        }

        if ($validUntil !== null && $projectionReferenceAt->greaterThanOrEqualTo($validUntil)) {
            return 'A2';
        }

        return 'A1';
    }

    /**
     * @return array{
     *     kind: string,
     *     fact_id: ?string,
     *     occurred_at: CarbonImmutable,
     *     actor: ?array{id: string, display_name: string},
     *     stage: ?string,
     *     outcome: ?string,
     *     justification: ?string
     * }
     */
    private static function entry(
        string $kind,
        ?string $factId,
        CarbonImmutable $occurredAt,
        ?ActorReference $actor,
        ?string $stage = null,
        ?string $outcome = null,
        ?string $justification = null,
    ): array {
        return [
            'kind' => $kind,
            'fact_id' => $factId,
            'occurred_at' => $occurredAt,
            'actor' => $actor === null ? null : self::actor($actor),
            'stage' => $stage,
            'outcome' => $outcome,
            'justification' => $justification,
        ];
    }

    /**
     * @return array{id: string, display_name: string}
     */
    private static function actor(ActorReference $actor): array
    {
        return [
            'id' => $actor->id,
            'display_name' => $actor->display_name,
        ];
    }
}
