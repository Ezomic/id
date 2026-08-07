<?php

use App\Models\AccessAudit;
use App\Models\AuthorizedClient;
use App\Models\LogoutNotification;
use App\Models\SignInEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Seven consumer apps refreshing 15 minute access tokens fill the Passport
// tables faster than anything else in this database.
Schedule::command('passport:purge')->daily();

Schedule::command('model:prune', ['--model' => [
    SignInEvent::class,
    AccessAudit::class,
    AuthorizedClient::class,
    LogoutNotification::class,
]])->daily();

Schedule::command('id:prune-login-codes')->hourly();

// A consumer that was down when someone signed out is still holding a live
// session until this lands.
Schedule::command('id:retry-logout-notifications')->everyFiveMinutes();
