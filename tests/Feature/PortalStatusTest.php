<?php

use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('services.status.url', 'https://status.test/api/status');
    config()->set('services.status.token', 'test-token');
    Cache::flush();
});

it('merges live service status onto the matching app cards by slug', function () {
    $user = User::factory()->create();
    Application::create(['name' => 'CMS', 'slug' => 'cms', 'active' => true]);
    Application::create(['name' => 'Zero', 'slug' => 'zero', 'active' => true]);
    Application::create(['name' => 'Billr', 'slug' => 'billr', 'active' => true]);

    Http::fake([
        'status.test/*' => Http::response(['services' => [
            ['slug' => 'cms', 'state' => 'up', 'last_checked_at' => now()->toIso8601String()],
            ['slug' => 'zero', 'state' => 'down', 'last_checked_at' => null],
            // No entry for billr -> that card gets no dot.
        ]]),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('applications', function ($apps) {
                $bySlug = collect($apps)->keyBy('slug');

                return $bySlug['cms']['status'] === 'up'
                    && $bySlug['zero']['status'] === 'down'
                    && $bySlug['billr']['status'] === null;
            })
        );

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('renders the portal with no dots when the Status app is unreachable', function () {
    $user = User::factory()->create();
    Application::create(['name' => 'CMS', 'slug' => 'cms', 'active' => true]);

    Http::fake(['status.test/*' => Http::response('down', 500)]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('applications.0.status', null)
        );
});

it('does not call Status when the endpoint is not configured', function () {
    config()->set('services.status.url', null);
    Http::fake();

    $user = User::factory()->create();
    Application::create(['name' => 'CMS', 'slug' => 'cms', 'active' => true]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    Http::assertNothingSent();
});
