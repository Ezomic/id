<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

function seedSession(string $id, int $userId, string $ua = 'Mozilla/5.0'): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '203.0.113.10',
        'user_agent' => $ua,
        'payload' => 'x',
        'last_activity' => time(),
    ]);
}

it('lists only the current user\'s sessions', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    seedSession('sess-a', $user->id);
    seedSession('sess-b', $user->id);
    seedSession('sess-c', $other->id);

    $this->actingAs($user)
        ->get(route('sessions.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Sessions')
            ->has('sessions', 2)
        );
});

it('revokes another of your sessions', function () {
    $user = User::factory()->create();
    seedSession('sess-old', $user->id);

    $this->actingAs($user)
        ->delete(route('sessions.destroy', ['id' => 'sess-old']))
        ->assertRedirect();

    expect(DB::table('sessions')->where('id', 'sess-old')->exists())->toBeFalse();
});

it('cannot revoke another user\'s session', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    seedSession('sess-theirs', $other->id);

    $this->actingAs($user)
        ->delete(route('sessions.destroy', ['id' => 'sess-theirs']));

    expect(DB::table('sessions')->where('id', 'sess-theirs')->exists())->toBeTrue();
});

it('signs out other sessions but keeps you signed in', function () {
    $user = User::factory()->create();
    seedSession('other-1', $user->id);
    seedSession('other-2', $user->id);

    $this->actingAs($user)
        ->delete(route('sessions.destroyOthers'))
        ->assertRedirect();

    // The other sessions are gone and the acting user is still authenticated.
    expect(DB::table('sessions')->where('id', 'other-1')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'other-2')->exists())->toBeFalse();
    $this->assertAuthenticatedAs($user);
});
