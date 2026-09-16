<?php

declare(strict_types=1);

namespace App\AccessGovernance\Actions;

use App\Models\AccessRequest;
use App\Models\GovernanceMembership;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * UC-002 / RF-004 — the access requests actually pending the decision of one
 * actor, restricted to the authority that actor currently holds.
 */
final class ListPendingApprovals
{
    /**
     * @return Collection<int, AccessRequest>
     */
    public function execute(string $actorReferenceId): Collection
    {
        $holdsGovernanceAuthority = GovernanceMembership::query()
            ->whereKey($actorReferenceId)
            ->exists();

        return AccessRequest::query()
            // RN04: an actor never decides their own request, so it is not
            // presented as actionable for them.
            ->where('requester_actor_reference_id', '!=', $actorReferenceId)
            ->where(function (EloquentBuilder $scopes) use ($actorReferenceId, $holdsGovernanceAuthority): void {
                $scopes->where(function (EloquentBuilder $resourceOwnerScope) use ($actorReferenceId): void {
                    $resourceOwnerScope
                        ->where('current_state', 'S1')
                        ->whereExists(function (QueryBuilder $query) use ($actorReferenceId): void {
                            $query->select(DB::raw('1'))
                                ->from('access_profiles')
                                ->join('resources', 'resources.id', '=', 'access_profiles.resource_id')
                                ->whereColumn('access_profiles.id', 'access_requests.access_profile_id')
                                ->where('resources.resource_owner_actor_reference_id', $actorReferenceId);
                        });
                });

                if ($holdsGovernanceAuthority) {
                    $scopes->orWhere(function (EloquentBuilder $governanceScope): void {
                        // A request validly in S2 already belongs to the
                        // Privileged flow; the flow is a coherence guard only.
                        $governanceScope
                            ->where('current_state', 'S2')
                            ->where('approval_flow', 'privileged');
                    });
                }
            })
            ->get();
    }
}
