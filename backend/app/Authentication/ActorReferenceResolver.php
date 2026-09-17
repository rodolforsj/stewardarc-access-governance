<?php

declare(strict_types=1);

namespace App\Authentication;

use App\Authentication\Exceptions\UnresolvedExternalIdentity;
use App\Models\ActorReference;

/**
 * Resolves an authenticated external identity to its pre-provisioned Actor
 * Reference (ADR-010, ADR-011). It never creates or updates one, and it uses
 * nothing but `external_identity_key`, which is unique.
 */
final class ActorReferenceResolver
{
    /**
     * @throws UnresolvedExternalIdentity when no Actor Reference has the key
     */
    public function resolve(ExternalIdentityKey $key): ActorReference
    {
        $actor = ActorReference::query()
            ->where('external_identity_key', $key->value())
            ->first();

        if ($actor === null) {
            throw new UnresolvedExternalIdentity();
        }

        return $actor;
    }
}
