<?php

declare(strict_types=1);

namespace App\AccessGovernance\Actions;

use App\Models\AccessProfile;

/**
 * RF-001 / CA-001 — the Access Profiles currently available for new requests
 * (RN01). Request eligibility (RN03, RN07, RN11) stays with CreateAccessRequest.
 *
 * A single statement over the current catalog, so it needs no read transaction
 * of its own; the shape is internal and is not an API contract.
 */
final class ConsultAccessCatalog
{
    /**
     * @return list<array{
     *     access_profile_id: string,
     *     access_profile_name: string,
     *     classification: string,
     *     resource_id: string,
     *     resource_name: string
     * }>
     */
    public function execute(): array
    {
        return AccessProfile::query()
            ->join('resources', 'resources.id', '=', 'access_profiles.resource_id')
            ->where('access_profiles.is_available', true)
            // Technical tie-break for a deterministic result only; not a
            // presentation order.
            ->orderBy('access_profiles.id')
            ->get([
                'access_profiles.id as access_profile_id',
                'access_profiles.name as access_profile_name',
                'access_profiles.classification',
                'resources.id as resource_id',
                'resources.name as resource_name',
            ])
            ->map(static fn (AccessProfile $profile): array => [
                'access_profile_id' => (string) $profile->access_profile_id,
                'access_profile_name' => (string) $profile->access_profile_name,
                'classification' => (string) $profile->classification,
                'resource_id' => (string) $profile->resource_id,
                'resource_name' => (string) $profile->resource_name,
            ])
            ->values()
            ->all();
    }
}
