<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GrantedAccess extends Model
{
    use HasUuids;

    public $timestamps = false;

    public function grantConfirmation(): BelongsTo
    {
        return $this->belongsTo(GrantConfirmation::class);
    }

    public function revocationConfirmation(): HasOne
    {
        return $this->hasOne(RevocationConfirmation::class);
    }

    protected function casts(): array
    {
        return [
            'valid_until_at' => 'immutable_datetime',
        ];
    }
}
