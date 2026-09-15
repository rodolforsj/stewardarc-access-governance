<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    use HasUuids;

    public $timestamps = false;

    public function resourceOwner(): BelongsTo
    {
        return $this->belongsTo(ActorReference::class, 'resource_owner_actor_reference_id');
    }

    public function accessProfiles(): HasMany
    {
        return $this->hasMany(AccessProfile::class);
    }
}
