<?php

use App\Models\AccessAudit;
use App\Models\AuthorizedClient;
use App\Models\LogoutNotification;
use App\Models\PortalLookup;
use App\Models\SignInEvent;
use App\Services\SchedulerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Seven consumer apps refreshing 15 minute access tokens fill the Passport
// tables faster than anything else in this database.
// Written every minute so an external check can tell a stopped scheduler from
// a healthy one. Nothing else here proves the scheduler is alive.
Schedule::call(fn () => app(SchedulerHeartbeat::class)->record())
    ->everyMinute()
    ->name('scheduler-heartbeat');

Schedule::command('passport:purge')->daily();

Schedule::command('model:prune', ['--model' => [
    SignInEvent::class,
    AccessAudit::class,
    AuthorizedClient::class,
    LogoutNotification::class,
    PortalLookup::class,
]])->daily();

Schedule::command('id:prune-login-codes')->hourly();

// A consumer that was down when someone signed out is still holding a live
// session until this lands.
Schedule::command('id:retry-logout-notifications')->everyFiveMinutes();
