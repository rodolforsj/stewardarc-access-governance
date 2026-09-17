<?php

use App\Http\Controllers\Auth\OpenIdConnectController;
use Illuminate\Support\Facades\Route;

// The framework health check stays available at /up, outside these routes.

// OpenID Connect login lifecycle (ADR-011). These routes use the web middleware
// group: session, encrypted cookies and CSRF protection for the logout.
Route::controller(OpenIdConnectController::class)
    ->prefix('auth')
    ->name('auth.')
    ->group(function (): void {
        Route::get('login', 'login')->name('login');
        Route::get('callback', 'callback')->name('callback');
        Route::post('logout', 'logout')->name('logout');
    });
