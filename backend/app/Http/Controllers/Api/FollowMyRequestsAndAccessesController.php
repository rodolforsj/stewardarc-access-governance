<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\AccessGovernance\Actions\FollowMyRequestsAndAccesses;
use App\Http\Controllers\Controller;
use App\Models\ActorReference;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * UC-005 over HTTP (ADR-010): the authenticated Requester follows their own
 * requests, Granted Accesses and Functional History. The actor is the one in
 * the session and nothing else. The projection runs in its own read
 * transaction (ADR-008) and is already fully materialized when it is mapped,
 * field by field, to the public contract.
 */
final class FollowMyRequestsAndAccessesController extends Controller
{
    public function __invoke(
        #[CurrentUser] ActorReference $actor,
        FollowMyRequestsAndAccesses $followMyRequestsAndAccesses,
    ): JsonResponse {
        $projection = $followMyRequestsAndAccesses->execute($actor->getAuthIdentifier());

        return response()->json([
            'requests' => array_map(self::request(...), $projection['requests']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private static function request(array $request): array
    {
        $grantedAccess = $request['granted_access'];

        return [
            'id' => $request['request_id'],
            'access_profile' => [
                'id' => $request['access_profile_id'],
                'name' => $request['access_profile_name'],
            ],
            'resource' => [
                'name' => $request['resource_name'],
            ],
            // The Requester's own justification, exactly as stated.
            'justification' => $request['justification'],
            'approval_flow' => $request['approval_flow'],
            'requested_duration_seconds' => $request['requested_duration_seconds'],
            'current_state' => $request['current_state'],
            'requested_at' => self::instant($request['requested_at']),
            'granted_access' => $grantedAccess === null ? null : [
                'valid_until_at' => self::instant($grantedAccess['valid_until_at']),
                'state' => $grantedAccess['state'],
            ],
            'history' => array_map(self::historyEntry(...), $request['history']),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function historyEntry(array $entry): array
    {
        return [
            'kind' => $entry['kind'],
            'occurred_at' => self::instant($entry['occurred_at']),
            'actor' => $entry['actor'] === null ? null : [
                'id' => $entry['actor']['id'],
                'display_name' => $entry['actor']['display_name'],
            ],
            // Stage, outcome and the decision's own justification belong to
            // decisions only.
            'decision' => $entry['kind'] !== 'decision' ? null : [
                'stage' => $entry['stage'],
                'outcome' => $entry['outcome'],
                'justification' => $entry['justification'],
            ],
        ];
    }

    /**
     * RFC 3339 in UTC, to the second: the precision the functional instants
     * are stored with.
     */
    private static function instant(?CarbonImmutable $instant): ?string
    {
        return $instant?->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
