<?php

use App\Models\Bookmark;
use App\Models\User;
use Illuminate\Support\Facades\Http;

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
        ->image->toBeNull();
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
