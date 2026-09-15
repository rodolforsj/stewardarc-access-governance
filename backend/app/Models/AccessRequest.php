<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccessRequest extends Model
{
    use HasUuids;

    public $timestamps = false;

    public function requester(): BelongsTo
    {
        return $this->belongsTo(ActorReference::class, 'requester_actor_reference_id');
    }

    public function accessProfile(): BelongsTo
    {
        return $this->belongsTo(AccessProfile::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class);
    }

    public function grantConfirmation(): HasOne
    {
        return $this->hasOne(GrantConfirmation::class);
    }

    protected function casts(): array
    {
        return [
            'requested_duration_seconds' => 'integer',
            'requested_at' => 'immutable_datetime',
        ];
    }
}
