<?php

use App\Http\Controllers\Api\FollowMyRequestsAndAccessesController;
use Illuminate\Support\Facades\Route;

// The product API (ADR-010). bootstrap/app.php loads this file under /api, with
// route names prefixed "api.", the web middleware group and auth: every route
// here requires the authenticated session. A public route would need an
// explicit, visible exception.

// UC-005: the authenticated Requester's own requests, accesses and history.
Route::get('me/requests-and-accesses', FollowMyRequestsAndAccessesController::class)
    ->name('me.requests-and-accesses');
