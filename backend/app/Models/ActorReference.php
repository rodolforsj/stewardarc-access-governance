<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Also the authenticated principal of the session guard (ADR-011). It is not a
 * local account: it holds no password and no remember token.
 */
class ActorReference extends Model implements Authenticatable
{
    use HasUuids;

    public $timestamps = false;

    public function ownedResources(): HasMany
    {
        return $this->hasMany(Resource::class, 'resource_owner_actor_reference_id');
    }

    public function accessRequests(): HasMany
    {
        return $this->hasMany(AccessRequest::class, 'requester_actor_reference_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class, 'actor_reference_id');
    }

    public function grantConfirmations(): HasMany
    {
        return $this->hasMany(GrantConfirmation::class, 'actor_reference_id');
    }

    public function revocationConfirmations(): HasMany
    {
        return $this->hasMany(RevocationConfirmation::class, 'actor_reference_id');
    }

    public function governanceMembership(): HasOne
    {
        return $this->hasOne(GovernanceMembership::class, 'actor_reference_id');
    }

    /**
     * The session guard stores only this identifier: the Actor Reference id.
     */
    public function getAuthIdentifierName(): string
    {
        return $this->getKeyName();
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * No password exists. The framework treats a null password as a failed
     * credential check, so password authentication can never succeed.
     */
    public function getAuthPassword(): ?string
    {
        return null;
    }

    /**
     * Only reached to rewrite a password hash, which cannot happen here.
     */
    public function getAuthPasswordName(): never
    {
        throw new LogicException('Actor References have no password.');
    }

    /**
     * Remember-me is not supported: there is never a token to recall.
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    /**
     * Intentionally a no-op: no remember token is ever kept.
     */
    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): never
    {
        throw new LogicException('Actor References have no remember token.');
    }
}
