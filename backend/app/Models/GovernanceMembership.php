<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current Governance authority association (ADR-007). It has no identifier of
 * its own: the foreign key to the Actor Reference is also the primary key.
 */
class GovernanceMembership extends Model
{
    protected $primaryKey = 'actor_reference_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    public function actorReference(): BelongsTo
    {
        return $this->belongsTo(ActorReference::class, 'actor_reference_id');
    }
}
