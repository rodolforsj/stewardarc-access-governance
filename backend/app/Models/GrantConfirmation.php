<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GrantConfirmation extends Model
{
    use HasUuids;

    public $timestamps = false;

    public function accessRequest(): BelongsTo
    {
        return $this->belongsTo(AccessRequest::class);
    }

    public function actorReference(): BelongsTo
    {
        return $this->belongsTo(ActorReference::class);
    }

    public function grantedAccess(): HasOne
    {
        return $this->hasOne(GrantedAccess::class);
    }

    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
