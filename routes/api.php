<?php

use App\Http\Controllers\Api\EstateLogoutController;
use App\Http\Controllers\Api\PortalAppsController;
use App\Http\Controllers\Api\UserInfoController;
use Illuminate\Support\Facades\Route;

Route::get('/userinfo', UserInfoController::class)->middleware('auth:api');

// Authenticated as the user, not the client: an app can only end the session
// of the person whose token it holds.
Route::post('/sso/logout', EstateLogoutController::class)->middleware('auth:api');

Route::post('/portal/apps', PortalAppsController::class)->middleware(['client', 'throttle:portal-lookups']);
