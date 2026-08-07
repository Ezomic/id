<?php

use App\Services\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Schedule;

it('reports unhealthy when the scheduler has never run', function () {
    // The case that actually happened: everything registered, nothing invoking
    // it, and the app answering HTTP perfectly the whole time.
    $this->getJson(route('health.scheduler'))
        ->assertStatus(503)
        ->assertJson(['healthy' => false, 'last_run_at' => null]);
});

it('reports healthy once the scheduler has run', function () {
    app(SchedulerHeartbeat::class)->record();

    $this->getJson(route('health.scheduler'))
        ->assertOk()
        ->assertJson(['healthy' => true])
        ->assertJsonPath('stale_after_minutes', SchedulerHeartbeat::STALE_AFTER_MINUTES);
});

it('goes unhealthy again once the beat stops', function () {
    app(SchedulerHeartbeat::class)->record();

    $this->travel(SchedulerHeartbeat::STALE_AFTER_MINUTES + 1)->minutes();

    $this->getJson(route('health.scheduler'))->assertStatus(503);
});

it('tolerates a single slow minute', function () {
    app(SchedulerHeartbeat::class)->record();

    $this->travel(SchedulerHeartbeat::STALE_AFTER_MINUTES - 1)->minutes();

    $this->getJson(route('health.scheduler'))->assertOk();
});

it('records a beat when the schedule runs', function () {
    expect(app(SchedulerHeartbeat::class)->lastRunAt())->toBeNull();

    $this->artisan('schedule:run')->assertSuccessful();

    expect(app(SchedulerHeartbeat::class)->lastRunAt())->not->toBeNull()
        ->and(app(SchedulerHeartbeat::class)->isStale())->toBeFalse();
});

it('schedules the heartbeat every minute', function () {
    $heartbeat = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'scheduler-heartbeat');

    expect($heartbeat)->not->toBeNull()
        ->and($heartbeat->expression)->toBe('* * * * *');
});

it('is reachable without signing in', function () {
    // A health check that needs a session is not a health check.
    $this->getJson(route('health.scheduler'))->assertStatus(503);

    app(SchedulerHeartbeat::class)->record();

    $this->getJson(route('health.scheduler'))->assertOk();
});
