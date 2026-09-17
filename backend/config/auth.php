<?php

use App\Models\ActorReference;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| The authenticated principal is the Actor Reference, kept in the application
| session by the session guard (ADR-011). Identities are federated, so there
| are no passwords, password resets, remember tokens or API tokens here.
|
*/

return [

    'defaults' => [
        'guard' => 'web',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'actor_references',
        ],
    ],

    'providers' => [
        'actor_references' => [
            'driver' => 'eloquent',
            'model' => ActorReference::class,
        ],
    ],

];
