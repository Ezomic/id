<?php

use App\Models\AccessAudit;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

/**
 * @return array{0: Application, 1: array<string, mixed>}
 */
function connectedApp(User $user, string $slug = 'zero'): array
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

    return [$application, $token];
}

function seedAdminSession(User $user, string $id = 'session-one'): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '198.51.100.5',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);
}

it('shows an admin a user\'s sessions and connected apps', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    connectedApp($user);
    seedAdminSession($user);

    $this->actingAs($admin)
        ->get(route('admin.users.show', $user))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/UserDetail')
            ->where('user.email', $user->email)
            ->has('sessions', 1)
            ->where('sessions.0.device', 'Chrome on macOS')
            ->has('connections', 1)
            ->where('connections.0.name', 'Zero')
            ->where('connections.0.tokens', 1)
        );
});

it('signs a user out everywhere and revokes their tokens', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    connectedApp($user, 'zero');
    connectedApp($user, 'billr');
    seedAdminSession($user);

    expect(Token::where('user_id', $user->id)->count())->toBe(2);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

    $this->actingAs($admin)
        ->post(route('admin.users.sign-out', $user))
        ->assertRedirect();

    expect(Token::where('user_id', $user->id)->count())->toBe(0)
        ->and(RefreshToken::count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('records the force sign-out against the acting admin', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)->post(route('admin.users.sign-out', $user));

    $audit = AccessAudit::where('action', 'force_sign_out')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($admin->id)
        ->and($audit->subject_user_id)->toBe($user->id);
});

it('leaves other users alone', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $bystander = User::factory()->create();

    connectedApp($bystander);
    seedAdminSession($bystander, 'bystander-session');

    $this->actingAs($admin)->post(route('admin.users.sign-out', $user));

    expect(Token::where('user_id', $bystander->id)->count())->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $bystander->id)->count())->toBe(1);
});

it('keeps the admin views away from non-admins', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->get(route('admin.users.show', $other))->assertForbidden();
    $this->actingAs($user)->post(route('admin.users.sign-out', $other))->assertForbidden();
});

it('lists connected apps in the user\'s own security settings', function () {
    $user = User::factory()->create();
    connectedApp($user);

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Security')
            ->has('connections', 1)
            ->where('connections.0.name', 'Zero')
        );
});

it('lets a user disconnect one app without losing access to it', function () {
    $user = User::factory()->create();
    [$zero] = connectedApp($user, 'zero');
    connectedApp($user, 'billr');

    $this->actingAs($user)
        ->delete(route('connections.destroy', $zero))
        ->assertRedirect(route('security.edit'));

    $remaining = Token::where('user_id', $user->id)->pluck('client_id')->all();

    expect($remaining)->toHaveCount(1)
        ->and($remaining[0])->not->toBe($zero->oauth_client_id)
        // Disconnecting signs you out; it does not give up your grant.
        ->and($user->fresh()->canAccess($zero))->toBeTrue();
});

it('records a self-service disconnect', function () {
    $user = User::factory()->create();
    [$zero] = connectedApp($user);

    $this->actingAs($user)->delete(route('connections.destroy', $zero));

    $audit = AccessAudit::where('action', 'disconnect')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($user->id)
        ->and($audit->application_id)->toBe($zero->id);
});

it('cannot disconnect on behalf of another user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    [$zero] = connectedApp($other);

    $this->actingAs($user)->delete(route('connections.destroy', $zero));

    expect(Token::where('user_id', $other->id)->count())->toBe(1);
});
