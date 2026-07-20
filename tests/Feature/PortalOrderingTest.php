<?php

use App\Models\Application;
use App\Models\Bookmark;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('sorts pinned apps first, then by saved position', function () {
    $user = User::factory()->create();
    $alpha = Application::create(['name' => 'Alpha', 'slug' => 'alpha', 'active' => true]);
    $beta = Application::create(['name' => 'Beta', 'slug' => 'beta', 'active' => true]);
    $gamma = Application::create(['name' => 'Gamma', 'slug' => 'gamma', 'active' => true]);

    $user->applications()->attach($alpha, ['position' => 2]);
    $user->applications()->attach($beta, ['position' => 1]);
    $user->applications()->attach($gamma, ['pinned' => true]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('applications.0.name', 'Gamma')
            ->where('applications.1.name', 'Beta')
            ->where('applications.2.name', 'Alpha')
        );
});

it('sorts pinned bookmarks first', function () {
    $user = User::factory()->create();
    Bookmark::factory()->for($user)->create(['title' => 'Plain', 'position' => 1]);
    Bookmark::factory()->for($user)->create(['title' => 'Pinned', 'pinned' => true]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bookmarks.0.title', 'Pinned')
            ->where('bookmarks.1.title', 'Plain')
        );
});

it('launches an app, records the time, and redirects to its url', function () {
    $user = User::factory()->create();
    $app = Application::create([
        'name' => 'Zero',
        'slug' => 'zero',
        'active' => true,
        'launch_url' => 'https://zero.thijssensoftware.nl',
    ]);
    $user->applications()->attach($app);

    $this->actingAs($user)
        ->get(route('portal.launch', $app))
        ->assertRedirect('https://zero.thijssensoftware.nl');

    expect($user->applications()->find($app->id)->pivot->last_launched_at)->not->toBeNull();
});

it('will not launch an app the user cannot access', function () {
    $user = User::factory()->create();
    $app = Application::create([
        'name' => 'Zero',
        'slug' => 'zero',
        'active' => true,
        'launch_url' => 'https://zero.thijssensoftware.nl',
    ]);

    $this->actingAs($user)
        ->get(route('portal.launch', $app))
        ->assertForbidden();
});

it('surfaces recently launched apps newest first', function () {
    $user = User::factory()->create();
    $one = Application::create(['name' => 'One', 'slug' => 'one', 'active' => true, 'launch_url' => 'https://one.test']);
    $two = Application::create(['name' => 'Two', 'slug' => 'two', 'active' => true, 'launch_url' => 'https://two.test']);
    $user->applications()->attach([$one->id, $two->id]);

    $this->actingAs($user)->get(route('portal.launch', $one));
    $this->travel(1)->minute();
    $this->actingAs($user)->get(route('portal.launch', $two));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('recentApps', 2)
            ->where('recentApps.0.name', 'Two')
            ->where('recentApps.1.name', 'One')
        );
});

it('persists a new order for apps', function () {
    $user = User::factory()->create();
    $a = Application::create(['name' => 'A', 'slug' => 'a', 'active' => true]);
    $b = Application::create(['name' => 'B', 'slug' => 'b', 'active' => true]);
    $user->applications()->attach([$a->id, $b->id]);

    $this->actingAs($user)
        ->patch(route('portal.reorder'), ['type' => 'app', 'ids' => [$b->id, $a->id]])
        ->assertSessionHasNoErrors();

    expect((int) $user->applications()->find($b->id)->pivot->position)->toBe(0)
        ->and((int) $user->applications()->find($a->id)->pivot->position)->toBe(1);
});

it('persists a new order for bookmarks', function () {
    $user = User::factory()->create();
    $first = Bookmark::factory()->for($user)->create();
    $second = Bookmark::factory()->for($user)->create();

    $this->actingAs($user)
        ->patch(route('portal.reorder'), ['type' => 'bookmark', 'ids' => [$second->id, $first->id]])
        ->assertSessionHasNoErrors();

    expect($second->refresh()->position)->toBe(0)
        ->and($first->refresh()->position)->toBe(1);
});

it('pins and unpins an app', function () {
    $user = User::factory()->create();
    $app = Application::create(['name' => 'A', 'slug' => 'a', 'active' => true]);
    $user->applications()->attach($app);

    $this->actingAs($user)
        ->patch(route('portal.pin'), ['type' => 'app', 'id' => $app->id, 'pinned' => true])
        ->assertSessionHasNoErrors();

    expect((bool) $user->applications()->find($app->id)->pivot->pinned)->toBeTrue();
});

it('pins and unpins a bookmark', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create(['pinned' => false]);

    $this->actingAs($user)
        ->patch(route('portal.pin'), ['type' => 'bookmark', 'id' => $bookmark->id, 'pinned' => true])
        ->assertSessionHasNoErrors();

    expect($bookmark->refresh()->pinned)->toBeTrue();
});

it('keeps guests out of the portal actions', function () {
    $app = Application::create(['name' => 'A', 'slug' => 'a', 'active' => true, 'launch_url' => 'https://a.test']);

    $this->get(route('portal.launch', $app))->assertRedirect(route('login'));
    $this->patch(route('portal.reorder'), ['type' => 'app', 'ids' => []])->assertRedirect(route('login'));
    $this->patch(route('portal.pin'), ['type' => 'app', 'id' => $app->id, 'pinned' => true])->assertRedirect(route('login'));
});
