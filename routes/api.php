<?php

use App\Http\Controllers\Api\PortalAppsController;
use App\Http\Controllers\Api\UserInfoController;
use Illuminate\Support\Facades\Route;

Route::get('/userinfo', UserInfoController::class)->middleware('auth:api');

Route::post('/portal/apps', PortalAppsController::class)->middleware('client');
