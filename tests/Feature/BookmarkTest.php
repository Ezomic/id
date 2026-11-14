<?php

use App\Models\Bookmark;
use App\Models\User;
use App\Services\PublicAddressResolver;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/*
 * No real DNS in tests: names under .internal resolve to the private network,
 * every other name to a public address, and IP literals are themselves.
 */
beforeEach(function () {
    app()->instance(PublicAddressResolver::class, new class extends PublicAddressResolver
    {
        protected function addresses(string $host): array
        {
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return parent::addresses($host);
            }

            return [str_ends_with($host, '.internal') ? '10.0.0.5' : '93.184.216.34'];
        }
    });
});

it('lists only your own bookmarks, newest first', function () {
    $user = User::factory()->create();
    Bookmark::factory()->for($user)->create(['title' => 'Older']);
    $newest = Bookmark::factory()->for($user)->create(['title' => 'Newest']);
    $newest->forceFill(['created_at' => now()->addMinute()])->save();
    Bookmark::factory()->create(['title' => 'Someone else']);

    $this->actingAs($user)
        ->get(route('bookmarks.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Bookmarks')
            ->has('bookmarks', 2)
            ->where('bookmarks.0.title', 'Newest')
            ->where('bookmarks.0.read', false)
            ->where('bookmarks.1.title', 'Older')
        );
});

it('saves a link and pulls its title and preview image', function () {
    Http::fake([
        'example.com/*' => Http::response(
            '<html><head><title>Ignored</title>'
            .'<meta property="og:title" content="A very good article">'
            .'<meta property="og:image" content="/cover.png">'
            .'</head></html>'
        ),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bookmarks.store'), ['url' => 'example.com/post', 'tags' => ['reading']])
        ->assertSessionHasNoErrors();

    $bookmark = $user->bookmarks()->sole();

    expect($bookmark->url)->toBe('https://example.com/post')
        ->and($bookmark->title)->toBe('A very good article')
        ->and($bookmark->image)->toBe('https://example.com/cover.png')
        ->and($bookmark->domain)->toBe('example.com')
        ->and($bookmark->tags)->toBe(['reading'])
        ->and($bookmark->isRead())->toBeFalse();
});

it('falls back to the <title> tag when there is no og:title', function () {
    Http::fake(['*' => Http::response('<html><head><title>Plain title</title></head></html>')]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bookmarks.store'), ['url' => 'https://www.example.org/x'])
        ->assertSessionHasNoErrors();

    expect($user->bookmarks()->sole())
        ->title->toBe('Plain title')
        ->domain->toBe('example.org');
});

it('still saves the link when the page cannot be fetched', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bookmarks.store'), ['url' => 'https://down.example.com/a'])
        ->assertSessionHasNoErrors();

    expect($user->bookmarks()->sole())
        ->title->toBe('down.example.com')
        ->image->toBeNull()
        ->favicon->toBeNull();
});

it('resolves the favicon from a link rel=icon tag', function () {
    Http::fake([
        'example.com/*' => Http::response(
            '<html><head><title>x</title>'
            .'<link rel="icon" href="/assets/favicon.png">'
            .'</head></html>'
        ),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bookmarks.store'), ['url' => 'example.com/post'])
        ->assertSessionHasNoErrors();

    expect($user->bookmarks()->sole()->favicon)->toBe('https://example.com/assets/favicon.png');
});

it('falls back to /favicon.ico when the page has no icon link', function () {
    Http::fake(['example.org/*' => Http::response('<html><head><title>x</title></head></html>')]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bookmarks.store'), ['url' => 'https://www.example.org/x'])
        ->assertSessionHasNoErrors();

    expect($user->bookmarks()->sole()->favicon)->toBe('https://www.example.org/favicon.ico');
});

it('requires a url', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bookmarks.store'), ['url' => ''])
        ->assertSessionHasErrors('url');
});

it('marks a bookmark read and archived', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('bookmarks.update', $bookmark), ['read' => true, 'archived' => true])
        ->assertSessionHasNoErrors();

    $bookmark->refresh();

    expect($bookmark->isRead())->toBeTrue()
        ->and($bookmark->isArchived())->toBeTrue();

    $this->actingAs($user)
        ->put(route('bookmarks.update', $bookmark), ['read' => false, 'archived' => false]);

    $bookmark->refresh();

    expect($bookmark->isRead())->toBeFalse()
        ->and($bookmark->isArchived())->toBeFalse();
});

it('updates the note and tags without touching the url', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create(['url' => 'https://keep.me/here']);

    $this->actingAs($user)
        ->put(route('bookmarks.update', $bookmark), ['note' => 'Read before Friday', 'tags' => ['work']])
        ->assertSessionHasNoErrors();

    expect($bookmark->refresh())
        ->note->toBe('Read before Friday')
        ->tags->toBe(['work'])
        ->url->toBe('https://keep.me/here');
});

it('deletes a bookmark', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('bookmarks.destroy', $bookmark))
        ->assertSessionHasNoErrors();

    expect(Bookmark::count())->toBe(0);
});

it('will not let someone touch another persons bookmark', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $bookmark = Bookmark::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->put(route('bookmarks.update', $bookmark), ['read' => true])
        ->assertForbidden();

    $this->actingAs($intruder)
        ->delete(route('bookmarks.destroy', $bookmark))
        ->assertForbidden();

    expect($bookmark->refresh()->isRead())->toBeFalse();
});

it('keeps guests out', function () {
    $bookmark = Bookmark::factory()->create();

    $this->post(route('bookmarks.store'), ['url' => 'https://example.com'])->assertRedirect(route('login'));
    $this->delete(route('bookmarks.destroy', $bookmark))->assertRedirect(route('login'));
});

it('scopes unread and active bookmarks', function () {
    $user = User::factory()->create();
    Bookmark::factory()->for($user)->create();
    Bookmark::factory()->for($user)->read()->create();
    Bookmark::factory()->for($user)->archived()->create();

    expect($user->bookmarks()->unread()->count())->toBe(1)
        ->and($user->bookmarks()->active()->count())->toBe(2);
});

/*
 * ID-114. The fetched title is shown back to the user, so a bookmark of an
 * internal address read that service's page through ID.
 */
it('does not fetch internal addresses', function (string $url) {
    Http::fake(['*' => Http::response('<html><head><title>Internal admin</title></head></html>')]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bookmarks.store'), ['url' => $url])
        ->assertSessionHasNoErrors();

    Http::assertNothingSent();
    expect($user->bookmarks()->sole()->title)->not->toBe('Internal admin');
})->with([
    'loopback' => 'http://127.0.0.1:8080/admin',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'private network' => 'http://10.1.2.3/',
    'IPv6 loopback' => 'http://[::1]/',
    'name that resolves inside' => 'https://intranet.internal/',
]);

it('does not follow a redirect to an internal address', function () {
    Http::fake([
        'public.example.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1:8080/admin']),
        '*' => Http::response('<html><head><title>Internal admin</title></head></html>'),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bookmarks.store'), ['url' => 'https://public.example.com/go'])
        ->assertSessionHasNoErrors();

    Http::assertSentCount(1);
    expect($user->bookmarks()->sole()->title)->toBe('public.example.com');
});

it('still follows a redirect to another public page', function () {
    Http::fake([
        'old.example.net/*' => Http::response('', 301, ['Location' => 'https://new.example.org/post']),
        'new.example.org/*' => Http::response('<html><head><title>Moved here</title></head></html>'),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bookmarks.store'), ['url' => 'https://old.example.net/post'])
        ->assertSessionHasNoErrors();

    expect($user->bookmarks()->sole()->title)->toBe('Moved here');
});
