<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Decision extends Model
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

    protected function casts(): array
    {
        return [
            'decided_at' => 'immutable_datetime',
        ];
    }
}
