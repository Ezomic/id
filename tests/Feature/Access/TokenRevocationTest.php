<?php

use App\Models\Application;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

/**
 * Tokens are minted through the real authorization_code + PKCE flow rather than
 * inserted by hand, so these tests fail if revocation stops matching how tokens
 * are actually issued.
 *
 * @return array{0: Application, 1: array<string, mixed>, 2: Client}
 */
function grantedToken(User $user, string $slug = 'zero'): array
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: ucfirst($slug),
        redirectUris: ["https://{$slug}.test/auth/sso/callback"],
        confidential: true,
    );

    $application = Application::create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'oauth_client_id' => $client->getKey(),
        'active' => true,
    ]);

    $user->applications()->syncWithoutDetaching([$application->id]);

    $verifier = Str::random(64);
    $challenge = strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');

    $response = test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => "https://{$slug}.test/auth/sso/callback",
        'response_type' => 'code',
        'scope' => '',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]));

    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    $token = test()->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'redirect_uri' => "https://{$slug}.test/auth/sso/callback",
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk()->json();

    app('auth')->forgetGuards();

    return [$application, $token, $client];
}

it('destroys access and refresh tokens when an admin revokes access', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    [$application] = grantedToken($user);

    expect(Token::where('user_id', $user->id)->count())->toBe(1);
    expect(RefreshToken::count())->toBe(1);

    $this->actingAs($admin)
        ->put(route('admin.users.access.update', $user), ['applications' => []])
        ->assertRedirect();

    expect(Token::where('user_id', $user->id)->count())->toBe(0);
    expect(RefreshToken::count())->toBe(0);
});

it('leaves tokens alone for applications the user still has access to', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    [$zero] = grantedToken($user, 'zero');
    [$billr] = grantedToken($user, 'billr');

    expect(Token::where('user_id', $user->id)->count())->toBe(2);

    $this->actingAs($admin)
        ->put(route('admin.users.access.update', $user), ['applications' => [$zero->id]])
        ->assertRedirect();

    $remaining = Token::where('user_id', $user->id)->pluck('client_id')->all();

    expect($remaining)->toHaveCount(1)
        ->and($remaining[0])->toBe($zero->oauth_client_id)
        ->and($billr->oauth_client_id)->not->toBe($zero->oauth_client_id);
});

it('revokes a refresh token so it can no longer be exchanged', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    [$application, $token, $client] = grantedToken($user);

    $exchange = fn () => test()->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $token['refresh_token'],
        'client_id' => $application->oauth_client_id,
        'client_secret' => $client->plainSecret,
    ]);

    // The same exchange has to work first, otherwise the assertion below would
    // pass on a malformed request rather than on the revocation.
    $exchange()->assertOk();

    app('auth')->forgetGuards();

    $this->actingAs($admin)
        ->put(route('admin.users.access.update', $user), ['applications' => []])
        ->assertRedirect();

    app('auth')->forgetGuards();

    $exchange()->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('revokes every token when a user deletes their account', function () {
    $user = User::factory()->create();

    grantedToken($user, 'zero');
    grantedToken($user, 'billr');

    expect(Token::where('user_id', $user->id)->count())->toBe(2);

    $this->actingAs($user)->delete(route('profile.destroy'))->assertRedirect('/');

    expect(Token::where('user_id', $user->id)->count())->toBe(0);
    expect(RefreshToken::count())->toBe(0);
    expect(User::find($user->id))->toBeNull();
});

it('revokes tokens when a group loses an application', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    [$application] = grantedToken($user);

    // Access now comes only from the group, so removing the app from the group
    // is what takes it away.
    $user->applications()->detach();
    $group = Group::create(['name' => 'Everyone']);
    $group->users()->attach($user);
    $group->applications()->attach($application);

    expect($user->fresh()->canAccess($application))->toBeTrue();
    expect(Token::where('user_id', $user->id)->count())->toBe(1);

    $this->actingAs($admin)
        ->put(route('admin.groups.update', $group), ['users' => [$user->id], 'applications' => []])
        ->assertRedirect();

    expect(Token::where('user_id', $user->id)->count())->toBe(0);
});

it('revokes tokens when a user is removed from a group', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    [$application] = grantedToken($user);

    $user->applications()->detach();
    $group = Group::create(['name' => 'Everyone']);
    $group->users()->attach($user);
    $group->applications()->attach($application);

    $this->actingAs($admin)
        ->put(route('admin.groups.update', $group), ['users' => [], 'applications' => [$application->id]])
        ->assertRedirect();

    expect(Token::where('user_id', $user->id)->count())->toBe(0);
});

it('revokes tokens when a group granting access is deleted', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    [$application] = grantedToken($user);

    $user->applications()->detach();
    $group = Group::create(['name' => 'Everyone']);
    $group->users()->attach($user);
    $group->applications()->attach($application);

    $this->actingAs($admin)
        ->delete(route('admin.groups.destroy', $group))
        ->assertRedirect();

    expect(Token::where('user_id', $user->id)->count())->toBe(0);
});
