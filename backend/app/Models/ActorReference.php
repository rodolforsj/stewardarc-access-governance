<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActorReference extends Model
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
}
