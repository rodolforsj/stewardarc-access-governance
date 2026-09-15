<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevocationConfirmation extends Model
{
    use HasUuids;

    public $timestamps = false;

    public function grantedAccess(): BelongsTo
    {
        return $this->belongsTo(GrantedAccess::class);
    }

    public function actorReference(): BelongsTo
    {
        return $this->belongsTo(ActorReference::class);
    }

    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
