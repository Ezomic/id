<?php

declare(strict_types=1);

use App\Actions\Access\SignOutEverywhere;
use App\Actions\Auth\ApproveQrLogin;
use App\Actions\Auth\ClaimQrLogin;
use App\Actions\Auth\CreateQrLogin;
use App\Actions\Auth\DenyQrLogin;
use App\Actions\Auth\RedeemQrLogin;
use App\Exceptions\QrLoginRejected;
use App\Models\QrLogin;
use App\Models\Setting;
use App\Models\SignInEvent;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\NewDeviceSignIn;
use App\Services\DeviceFingerprint;
use App\Services\QrLoginPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;

const QR_PC_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const QR_PHONE_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

/**
 * Switches the test client to another browser: its own session, signed in as
 * $user (confirmed a minute ago unless told otherwise) or signed out.
 *
 * @param  array<string, mixed>  $session
 */
function qrBrowser(?User $user = null, ?int $confirmedAt = null, array $session = []): void
{
    app('auth')->forgetGuards();
    test()->flushSession();

    if ($user !== null) {
        $session['auth.password_confirmed_at'] = $confirmedAt ?? Date::now()->subMinute()->unix();
        test()->actingAs($user);
    }

    test()->withSession($session);
}

/**
 * The signed-out PC asks for a QR. Returns the response data and the session
 * the PC browser now holds.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function qrPcStarts(): array
{
    qrBrowser();

    $data = test()
        ->withHeaders(['User-Agent' => QR_PC_AGENT])
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson(route('qr.create'))
        ->assertOk()
        ->json();

    return [$data, session()->all()];
}

/**
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function qrCreatorStarts(User $user): array
{
    qrBrowser($user);

    $data = test()->postJson(route('sign-in-another-device.store'))->assertOk()->json();

    return [$data, session()->all()];
}

function qrCode(array $data): string
{
    return str_replace('-', '', $data['short_code']);
}

it('creates a request for the signed-out device with a hashed token and a number to match', function () {
    [$data] = qrPcStarts();

    expect($data['qr'])->toStartWith('data:image/svg+xml;base64,')
        ->and($data['number'])->toBeInt()->toBeBetween(10, 99)
        ->and($data['short_code'])->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/')
        ->and($data['expires_in'])->toBe(120)
        ->and(array_keys($data))->not->toContain('token');

    $qrLogin = QrLogin::sole();

    expect($qrLogin->direction)->toBe('a')
        ->and($qrLogin->status)->toBe('pending')
        ->and($qrLogin->match_number)->toBe($data['number'])
        ->and($qrLogin->decoy_numbers)->toHaveCount(2)
        ->and($qrLogin->numberOptions())->toHaveCount(3)->toContain($data['number'])
        ->and(array_unique($qrLogin->numberOptions()))->toHaveCount(3)
        ->and($qrLogin->requester_user_agent)->toBe(QR_PC_AGENT)
        ->and($qrLogin->requester_ip)->toBe('198.51.100.7')
        ->and($qrLogin->requester_network)->toBe('198.51.100.0/24')
        ->and($qrLogin->expires_at->diffInSeconds(Date::now(), true))->toBeBetween(118, 120)
        ->and($qrLogin->token_hash)->not->toBe(qrCode($data))
        ->and($qrLogin->code_hash)->not->toBe(qrCode($data));

    $this->assertDatabaseMissing('qr_logins', ['code_hash' => qrCode($data)]);
    expect(json_encode($qrLogin->toArray()))->not->toContain('token_hash');
});

it('signs the PC in once the phone approves, as a normal session and not a trusted device', function () {
    Notification::fake();
    $user = User::factory()->create();
    [$data, $pc] = qrPcStarts();

    qrBrowser($user);
    $this->get(route('qr.show', $data['short_code']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/QrShow')
            ->where('mode', 'approve')
            ->where('email', $user->email)
            ->where('request.device', 'Chrome on Windows')
            ->where('request.ip', '198.51.100.7')
            ->where('request.network', '198.51.100.0/24')
            ->has('options', 3)
            ->where('options', fn ($options) => collect($options)->contains($data['number'])));

    $this->post(route('qr.approve', $data['short_code']), ['number' => $data['number']])
        ->assertRedirect(route('qr.show', $data['short_code']));

    $this->get(route('qr.show', $data['short_code']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'approved'));

    qrBrowser(session: $pc);
    $sessionBefore = session()->getId();

    $this->getJson(route('qr.status'))->assertOk()->assertJsonPath('status', 'approved');

    $this->withHeaders(['User-Agent' => QR_PC_AGENT])
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson(route('qr.redeem'))
        ->assertOk()
        ->assertJsonPath('redirect', route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($sessionBefore)
        ->and(session()->has('qr_login.requester'))->toBeFalse()
        ->and(TrustedDevice::count())->toBe(0)
        ->and(QrLogin::sole()->status)->toBe('redeemed')
        ->and(QrLogin::sole()->redeemed_at)->not->toBeNull();

    $event = SignInEvent::where('user_id', $user->id)->sole();
    expect($event->method)->toBe('qr')
        ->and($event->outcome)->toBe(SignInEvent::SUCCESS)
        ->and($event->ip_address)->toBe('198.51.100.7')
        ->and($event->device_fingerprint)->toBe((new DeviceFingerprint)->forUserAgent(QR_PC_AGENT));
});

it('hands the signed-in PC a remember cookie like every other way in', function () {
    $user = User::factory()->create();
    [$data, $pc] = qrPcStarts();

    qrBrowser($user);
    $this->post(route('qr.approve', $data['short_code']), ['number' => $data['number']]);

    qrBrowser(session: $pc);
    $response = $this->postJson(route('qr.redeem'))->assertOk();

    expect(collect($response->headers->getCookies())->map(fn ($cookie) => $cookie->getName())->implode(','))->toContain('remember_web_');
});

it('can still sign out afterwards, here and everywhere', function () {
    $user = User::factory()->create();
    [$data, $pc] = qrPcStarts();

    qrBrowser($user);
    $this->post(route('qr.approve', $data['short_code']), ['number' => $data['number']]);

    qrBrowser(session: $pc);
    $this->postJson(route('qr.redeem'))->assertOk();
    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();

    app(SignOutEverywhere::class)->handle($user);
    $this->assertDatabaseHas('access_audits', ['action' => 'force_sign_out', 'subject_user_id' => $user->id]);
});

it('tells a returning user that a sign-in from a new device came in by QR', function () {
    Notification::fake();
    $user = User::factory()->create();
    SignInEvent::create([
        'user_id' => $user->id, 'method' => 'email_code', 'outcome' => SignInEvent::SUCCESS,
        'ip_address' => '203.0.113.9', 'network' => '203.0.113.0/24', 'user_agent' => 'Firefox/1 Linux',
        'device_fingerprint' => (new DeviceFingerprint)->forUserAgent('Firefox/1 Linux'),
    ]);
    [$data, $pc] = qrPcStarts();

    qrBrowser($user);
    $this->post(route('qr.approve', $data['short_code']), ['number' => $data['number']]);

    qrBrowser(session: $pc);
    $this->postJson(route('qr.redeem'))->assertOk();

    Notification::assertSentTo($user, NewDeviceSignIn::class, function (NewDeviceSignIn $notification) use ($user) {
        return collect($notification->toMail($user)->introLines)->contains('Method: QR code');
    });
});

it('opens a request from the long token as well as the short code', function () {
    $policy = app(QrLoginPolicy::class);
    $user = User::factory()->create();
    $token = $policy->newToken();
    QrLogin::create([
        'token_hash' => $policy->hash($token), 'code_hash' => $policy->hash('ABCDEFGH'), 'direction' => 'a',
        'status' => 'pending', 'match_number' => 42, 'decoy_numbers' => [11, 77], 'binding_hash' => $policy->bindingHash('x'),
        'expires_at' => Date::now()->addMinutes(2),
    ]);

    expect(strlen($token))->toBe(43)
        ->and($policy->find($token))->not->toBeNull()
        ->and($policy->find('abcd-efgh'))->not->toBeNull()
        ->and($policy->find('ABCDEFGX'))->toBeNull()
        ->and($policy->find($token.'x'))->toBeNull();

    qrBrowser($user);
    $this->get(route('qr.show', $token))->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'approve'));
});

it('offers three numbers and sends a guess at the wrong one nowhere', function () {
    $user = User::factory()->create();
    [$data, $pc] = qrPcStarts();
    $qrLogin = QrLogin::sole();
    $wrong = collect($qrLogin->decoy_numbers)->first();

    qrBrowser($user);
    $this->post(route('qr.approve', $data['short_code']), ['number' => $wrong])
        ->assertRedirect(route('qr.show', $data['short_code']))
        ->assertSessionHas('qr_notice');

    expect($qrLogin->refresh()->status)->toBe('denied')
        ->and($qrLogin->approver_user_id)->toBeNull();

    $this->post(route('qr.approve', $data['short_code']), ['number' => $data['number']])
        ->assertSessionHas('qr_notice');
    expect($qrLogin->refresh()->status)->toBe('denied');

    qrBrowser(session: $pc);
    $this->getJson(route('qr.status'))->assertJsonPath('status', 'denied');
    $this->postJson(route('qr.redeem'))->assertStatus(422)->assertJsonPath('reason', 'used');
    $this->assertGuest();
});

it('answers a wrong number with a JSON reason for a script', function () {
    $user = User::factory()->create();
    [$data] = qrPcStarts();
    $wrong = collect(QrLogin::sole()->decoy_numbers)->first();

    qrBrowser($user);
    $this->postJson(route('qr.approve', $data['short_code']), ['number' => $wrong])
        ->assertStatus(422)->assertJsonPath('reason', 'wrong_number');
});

it('validates the chosen number', function (mixed $number) {
    qrPcStarts();
    qrBrowser(User::factory()->create());

    $this->postJson(route('qr.approve', 'ABCD-EFGH'), ['number' => $number])->assertStatus(422);
})->with([[null], ['abc'], [5], [100]]);

it('lets the approver decline, and nobody is signed in', function () {
    $user = User::factory()->create();
    [$data, $pc] = qrPcStarts();

    qrBrowser($user, confirmedAt: Date::now()->subHour()->unix());
    $this->post(route('qr.deny', $data['short_code']))->assertRedirect(route('qr.show', $data['short_code']));

    expect(QrLogin::sole()->status)->toBe('denied');

    $this->get(route('qr.show', $data['short_code']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'denied'));

    qrBrowser(session: $pc);
    $this->getJson(route('qr.status'))->assertJsonPath('status', 'denied');
    $this->postJson(route('qr.redeem'))->assertStatus(422);
    $this->assertGuest();
});

it('refuses a request after two minutes', function () {
    $user = User::factory()->create();
    [$data, $pc] = qrPcStarts();

    $this->travel(121)->seconds();

    qrBrowser($user);
    $this->get(route('qr.show', $data['short_code']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'expired'));
    $this->postJson(route('qr.approve', $data['short_code']), ['number' => $data['number']])
        ->assertStatus(410)->assertJsonPath('reason', 'expired');
    $this->postJson(route('qr.deny', $data['short_code']))->assertStatus(410);

    qrBrowser(session: $pc);
    $this->getJson(route('qr.status'))->assertJsonPath('status', 'expired');
    $this->postJson(route('qr.redeem'))->assertStatus(410);
    $this->assertGuest();
});

it('refuses to redeem an approval that expired before the PC collected it', function () {
    $user = User::factory()->create();
    [$data, $pc] = qrPcStarts();

    $this->travel(100)->seconds();
    qrBrowser($user);
    $this->post(route('qr.approve', $data['short_code']), ['number' => $data['number']]);

    $this->travel(30)->seconds();
    qrBrowser(session: $pc);
    $this->getJson(route('qr.status'))->assertJsonPath('status', 'expired');
    $this->postJson(route('qr.redeem'))->assertStatus(410)->assertJsonPath('reason', 'expired');
    $this->assertGuest();
});

it('signs in once only: a replayed redeem does nothing', function () {
    $user = User::factory()->create();
    [$data, $pc] = qrPcStarts();

    qrBrowser($user);
    $this->post(route('qr.approve', $data['short_code']), ['number' => $data['number']]);

    qrBrowser(session: $pc);
    $this->postJson(route('qr.redeem'))->assertOk();

    qrBrowser(session: $pc);
    $this->postJson(route('qr.redeem'))->assertStatus(422)->assertJsonPath('reason', 'used');
    $this->assertGuest();

    qrBrowser($user);
    $this->post(route('qr.approve', $data['short_code']), ['number' => $data['number']])
        ->assertSessionHas('qr_notice');
    expect(QrLogin::sole()->status)->toBe('redeemed');
});

it('cannot be redeemed before anyone approved it', function () {
    [, $pc] = qrPcStarts();

    qrBrowser(session: $pc);
    $this->postJson(route('qr.redeem'))->assertStatus(422)->assertJsonPath('reason', 'not_approved');
    $this->assertGuest();
});

it('does not let a second browser redeem, even holding the code and the approval', function () {
    $user = User::factory()->create();
    [$data] = qrPcStarts();

    qrBrowser($user);
    $this->post(route('qr.approve', $data['short_code']), ['number' => $data['number']]);

    qrBrowser();
    $this->postJson(route('qr.redeem'))->assertStatus(403)->assertJsonPath('reason', 'not_bound');
    $this->getJson(route('qr.status'))->assertJsonPath('status', 'none');

    qrBrowser(session: ['qr_login.requester' => ['id' => QrLogin::sole()->id, 'secret' => 'guess']]);
    $this->postJson(route('qr.redeem'))->assertStatus(403);

    qrBrowser(session: ['qr_login.requester' => 'garbage']);
    $this->postJson(route('qr.redeem'))->assertStatus(403);

    qrBrowser(session: ['qr_login.requester' => ['id' => 9999, 'secret' => 'guess']]);
    $this->postJson(route('qr.redeem'))->assertStatus(403);

    $this->assertGuest();
    expect(QrLogin::sole()->status)->toBe('approved');
});

it('sends a signed-out phone that opens a PC code to sign in first, and brings it back', function () {
    [$data] = qrPcStarts();

    qrBrowser();
    $this->get(route('qr.show', $data['short_code']))->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe(route('qr.show', $data['short_code']));

    $this->postJson(route('qr.approve', $data['short_code']), ['number' => $data['number']])->assertUnauthorized();
});

it('sends a phone whose last confirmation is too old to re-authenticate and back', function (?int $age) {
    $user = User::factory()->create();
    [$data] = qrPcStarts();

    qrBrowser($user, confirmedAt: $age === null ? null : Date::now()->subSeconds($age)->unix());
    if ($age === null) {
        session()->forget('auth.password_confirmed_at');
    }

    $this->get(route('qr.show', $data['short_code']))->assertRedirect(route('reauthenticate.show'));
    expect(session('url.intended'))->toBe(route('qr.show', $data['short_code']));

    $this->post(route('qr.approve', $data['short_code']), ['number' => $data['number']])
        ->assertRedirect(route('reauthenticate.show'));

    $this->postJson(route('qr.approve', $data['short_code']), ['number' => $data['number']])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'reauth_required')
        ->assertJsonPath('reauthenticate', route('reauthenticate.show'));

    expect(QrLogin::sole()->status)->toBe('pending');
})->with([
    'never confirmed' => [null],
    'eleven minutes ago' => [11 * 60],
    'just over ten minutes ago' => [10 * 60 + 1],
]);

it('accepts a confirmation made just under ten minutes ago', function () {
    $user = User::factory()->create();
    [$data] = qrPcStarts();

    qrBrowser($user, confirmedAt: Date::now()->subSeconds(10 * 60 - 5)->unix());
    $this->post(route('qr.approve', $data['short_code']), ['number' => $data['number']])
        ->assertRedirect(route('qr.show', $data['short_code']));

    expect(QrLogin::sole()->status)->toBe('approved');
});

it('retires the previous request when the same browser asks for another', function () {
    [$first, $pc] = qrPcStarts();

    qrBrowser(session: $pc);
    $second = $this->postJson(route('qr.create'))->assertOk()->json();

    expect(QrLogin::count())->toBe(2)
        ->and(QrLogin::where('status', 'expired')->count())->toBe(1)
        ->and($second['short_code'])->not->toBe($first['short_code']);

    qrBrowser(User::factory()->create());
    $this->post(route('qr.approve', $first['short_code']), ['number' => $first['number']])
        ->assertSessionHas('qr_notice');
});

it('shows the sign-in page option only while the switch is on', function () {
    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->where('qrLoginEnabled', true));

    Setting::putFlag(QrLoginPolicy::SETTING, false);
    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->where('qrLoginEnabled', false));
});

it('refuses every endpoint while the global switch is off', function () {
    $user = User::factory()->create();
    [$data, $pc] = qrPcStarts();
    qrBrowser($user);
    $this->post(route('qr.approve', $data['short_code']), ['number' => $data['number']]);
    [$creator] = qrCreatorStarts($user);

    Setting::putFlag(QrLoginPolicy::SETTING, false);

    qrBrowser(session: $pc);
    $this->postJson(route('qr.create'))->assertNotFound();
    $this->getJson(route('qr.status'))->assertNotFound();
    $this->postJson(route('qr.redeem'))->assertNotFound();
    $this->postJson(route('qr.code'), ['code' => $data['short_code']])->assertNotFound();
    $this->get(route('qr.show', $data['short_code']))->assertNotFound();
    $this->post(route('qr.claim', $data['short_code']))->assertNotFound();
    $this->assertGuest();

    qrBrowser($user);
    $this->postJson(route('qr.approve', $data['short_code']), ['number' => 10])->assertNotFound();
    $this->postJson(route('qr.deny', $data['short_code']))->assertNotFound();
    $this->get(route('sign-in-another-device.edit'))->assertNotFound();
    $this->postJson(route('sign-in-another-device.store'))->assertNotFound();
    $this->get(route('profile.edit'))->assertInertia(fn (AssertableInertia $page) => $page->where('qrLoginEnabled', false));
    expect($creator['short_code'])->not->toBeEmpty();
});

it('refuses to approve or deny when the request has no user, or the switch is off', function () {
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));

    expect(fn () => app(ApproveQrLogin::class)->handle($request, new QrLogin, 10))
        ->toThrow(fn (QrLoginRejected $rejected) => $rejected->reason === 'forbidden')
        ->and(fn () => app(DenyQrLogin::class)->handle($request, new QrLogin))
        ->toThrow(fn (QrLoginRejected $rejected) => $rejected->reason === 'forbidden');

    Setting::putFlag(QrLoginPolicy::SETTING, false);
    $request->setUserResolver(fn () => User::factory()->create());

    foreach ([
        fn () => app(CreateQrLogin::class)->handle($request, 'a'),
        fn () => app(RedeemQrLogin::class)->handle($request),
        fn () => app(ClaimQrLogin::class)->handle($request, new QrLogin),
        fn () => app(ApproveQrLogin::class)->handle($request, new QrLogin, 10),
        fn () => app(DenyQrLogin::class)->handle($request, new QrLogin),
    ] as $call) {
        expect($call)->toThrow(fn (QrLoginRejected $rejected) => $rejected->reason === 'disabled');
    }
});

it('stops one browser from guessing short codes', function () {
    qrBrowser();

    foreach (range(1, 10) as $i) {
        $this->post(route('qr.code'), ['code' => 'ZZZZ-ZZZ'.($i % 8)])->assertSessionHasErrors('code');
    }

    $this->post(route('qr.code'), ['code' => 'ZZZZ-ZZZZ'])->assertStatus(429);
    $this->get(route('qr.show', 'ZZZZ-ZZZZ'))->assertStatus(429);
});

it('rate limits creating requests per address', function () {
    foreach (range(1, 10) as $i) {
        $this->postJson(route('qr.create'))->assertOk();
    }

    $this->postJson(route('qr.create'))->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->postJson(route('qr.create'))->assertOk();
});

it('keys the polling, redeeming and short-code limits on the session as well as the address', function (string $limiter, int $attempts) {
    qrBrowser();
    $request = Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.5']);
    $request->setLaravelSession(session()->driver());

    $limits = Arr::wrap(RateLimiter::limiter($limiter)($request));

    expect($limits)->not->toBeEmpty();

    foreach ($limits as $limit) {
        expect($limit->maxAttempts)->toBe($attempts)->and($limit->decaySeconds)->toBe(60);
    }

    expect(collect($limits)->contains(fn ($limit) => str_contains($limit->key, session()->getId())))->toBeTrue();
})->with([
    'polling' => ['qr-poll', 60],
    'redeeming' => ['qr-redeem', 10],
    'short codes' => ['qr-code', 10],
]);

it('rate limits approving per user', function () {
    $user = User::factory()->create();
    [$data] = qrPcStarts();
    qrBrowser($user);

    foreach (range(1, 10) as $i) {
        expect($this->postJson(route('qr.deny', $data['short_code']))->status())->not->toBe(429);
    }

    $this->postJson(route('qr.deny', $data['short_code']))->assertStatus(429);
});

it('rejects a typed code that matches nothing or is the wrong length', function (string $typed) {
    qrBrowser();

    $this->post(route('qr.code'), ['code' => $typed])->assertSessionHasErrors('code');
})->with(['short' => 'ABC', 'unknown' => 'ABCD-EFGH', 'empty' => '']);

it('takes a typed short code, in any case and spacing, to the right page', function () {
    $user = User::factory()->create();
    [$data] = qrPcStarts();

    qrBrowser($user);
    $typed = strtolower(substr($data['short_code'], 0, 4)).' '.strtolower(substr($data['short_code'], 5));
    $this->post(route('qr.code'), ['code' => $typed])->assertRedirect(route('qr.show', $data['short_code']));
});

it('answers an unknown code with a not-found page', function () {
    $this->get(route('qr.show', 'NOPE-NOPE'))->assertNotFound();
    qrBrowser(User::factory()->create());
    $this->postJson(route('qr.approve', 'NOPE-NOPE'), ['number' => 10])->assertNotFound();
    $this->postJson(route('qr.deny', 'NOPE-NOPE'))->assertNotFound();
    qrBrowser();
    $this->post(route('qr.claim', 'NOPE-NOPE'))->assertNotFound();
});

it('answers an expired request left idle as expired without anyone touching it', function () {
    $qrLogin = new QrLogin(['status' => 'pending', 'expires_at' => Date::now()->subSecond()]);
    expect($qrLogin->effectiveStatus())->toBe('expired');

    $qrLogin->status = 'denied';
    expect($qrLogin->effectiveStatus())->toBe('denied');
});

it('prunes old requests', function () {
    $policy = app(QrLoginPolicy::class);
    $row = fn (string $code, $createdAt) => tap(QrLogin::create([
        'token_hash' => $policy->hash('t'.$code), 'code_hash' => $policy->hash($code), 'direction' => 'a', 'status' => 'pending',
        'match_number' => 10, 'decoy_numbers' => [11, 12], 'expires_at' => $createdAt->addMinutes(2),
    ]), fn (QrLogin $qrLogin) => $qrLogin->forceFill(['created_at' => $createdAt])->save());

    $row('OLDOLDOL', Date::now()->subHours(25));
    $row('NEWNEWNE', Date::now()->subHours(23));

    $this->artisan('model:prune', ['--model' => [QrLogin::class]])->assertSuccessful();

    expect(QrLogin::count())->toBe(1);
});

describe('from a signed-in device (direction B)', function () {
    it('asks for a recent confirmation before showing the page or a code', function () {
        $user = User::factory()->create();

        qrBrowser($user, confirmedAt: Date::now()->subMinutes(11)->unix());
        $this->get(route('sign-in-another-device.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/SignInAnotherDevice')->where('confirmed', false)->where('windowMinutes', 10));
        expect(session('url.intended'))->toBe(route('sign-in-another-device.edit'));

        $this->postJson(route('sign-in-another-device.store'))->assertStatus(409)->assertJsonPath('reason', 'reauth_required');
        expect(QrLogin::count())->toBe(0);

        qrBrowser($user);
        $this->get(route('sign-in-another-device.edit'))->assertInertia(fn (AssertableInertia $page) => $page->where('confirmed', true));
        expect(session()->has('url.intended'))->toBeFalse();
    });

    it('requires being signed in', function () {
        qrBrowser();

        $this->get(route('sign-in-another-device.edit'))->assertRedirect(route('login'));
        $this->postJson(route('sign-in-another-device.store'))->assertUnauthorized();
    });

    it('signs a signed-out phone in once the signed-in device approves its number', function () {
        $user = User::factory()->create();
        [$creator, $desktop] = qrCreatorStarts($user);

        expect($creator['qr'])->toStartWith('data:image/svg+xml;base64,')
            ->and($creator['token'])->toHaveLength(43)
            ->and($creator['short_code'])->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/');

        $qrLogin = QrLogin::sole();
        expect($qrLogin->direction)->toBe('b')->and($qrLogin->approver_user_id)->toBe($user->id)->and($qrLogin->binding_hash)->toBeNull();

        $this->getJson(route('qr.status'))->assertJsonPath('status', 'pending')->assertJsonPath('claimed', false)->assertJsonMissingPath('options');

        qrBrowser();
        $this->get(route('qr.show', $creator['token']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'claim')->where('credential', $creator['token']));
        $this->post(route('qr.claim', $creator['token']))->assertRedirect(route('qr.show', $creator['token']));

        $this->withHeaders(['User-Agent' => QR_PHONE_AGENT])->get(route('qr.show', $creator['token']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'waiting')->where('number', $qrLogin->match_number));
        $phone = session()->all();

        $this->getJson(route('qr.status'))->assertJsonPath('status', 'pending')->assertJsonMissingPath('options');

        qrBrowser(session: $desktop);
        $this->actingAs($user);
        $status = $this->getJson(route('qr.status'))->assertJsonPath('claimed', true)->assertJsonPath('request.device', 'Symfony')->json();
        expect($status['options'])->toHaveCount(3)->toContain($qrLogin->match_number);

        $this->postJson(route('qr.approve', $creator['token']), ['number' => $qrLogin->match_number])
            ->assertOk()->assertJsonPath('status', 'approved');

        qrBrowser(session: $phone);
        $this->postJson(route('qr.redeem'))->assertOk();
        $this->assertAuthenticatedAs($user);
        expect(SignInEvent::where('user_id', $user->id)->sole()->method)->toBe('qr')
            ->and(TrustedDevice::count())->toBe(0);
    });

    it('records the device that opened the code for the approver to judge', function () {
        $user = User::factory()->create();
        [$creator, $desktop] = qrCreatorStarts($user);

        qrBrowser();
        $this->withHeaders(['User-Agent' => QR_PHONE_AGENT])->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
            ->post(route('qr.claim', $creator['short_code']))->assertRedirect();

        qrBrowser(session: $desktop);
        $this->actingAs($user);
        $this->getJson(route('qr.status'))
            ->assertJsonPath('request.device', 'Safari on iPhone')
            ->assertJsonPath('request.ip', '192.0.2.44')
            ->assertJsonPath('request.network', '192.0.2.0/24')
            ->assertJsonStructure(['request' => ['requested_at']]);
    });

    it('lets only the first device claim a code', function () {
        $user = User::factory()->create();
        [$creator] = qrCreatorStarts($user);

        qrBrowser();
        $this->withHeaders(['User-Agent' => QR_PHONE_AGENT])->post(route('qr.claim', $creator['token']));
        $first = QrLogin::sole()->binding_hash;

        qrBrowser();
        $this->withHeaders(['User-Agent' => QR_PC_AGENT])->post(route('qr.claim', $creator['token']))
            ->assertSessionHas('qr_notice', QrLoginRejected::because('already_claimed')->getMessage());
        $this->get(route('qr.show', $creator['token']))->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'taken'));

        expect(QrLogin::sole()->binding_hash)->toBe($first)
            ->and(QrLogin::sole()->requester_user_agent)->toBe(QR_PHONE_AGENT);
        $this->postJson(route('qr.redeem'))->assertStatus(403);
    });

    it('does not let the claim be used by a browser that never claimed', function () {
        $user = User::factory()->create();
        [$creator, $desktop] = qrCreatorStarts($user);

        qrBrowser();
        $this->post(route('qr.claim', $creator['token']));

        qrBrowser(session: $desktop);
        $this->actingAs($user);
        $this->postJson(route('qr.approve', $creator['token']), ['number' => QrLogin::sole()->match_number])->assertOk();

        qrBrowser();
        $this->postJson(route('qr.redeem'))->assertStatus(403)->assertJsonPath('reason', 'not_bound');
        $this->assertGuest();
    });

    it('refuses to approve before a device has opened the code', function () {
        $user = User::factory()->create();
        [$creator] = qrCreatorStarts($user);

        $this->postJson(route('qr.approve', $creator['token']), ['number' => QrLogin::sole()->match_number])
            ->assertStatus(422)->assertJsonPath('reason', 'not_claimed');
    });

    it('lets only the user whose device showed the code answer it', function () {
        $user = User::factory()->create();
        [$creator] = qrCreatorStarts($user);

        qrBrowser();
        $this->post(route('qr.claim', $creator['token']));

        qrBrowser(User::factory()->create());
        $this->postJson(route('qr.approve', $creator['token']), ['number' => QrLogin::sole()->match_number])
            ->assertStatus(403)->assertJsonPath('reason', 'forbidden');
        $this->postJson(route('qr.deny', $creator['token']))->assertStatus(403);

        expect(QrLogin::sole()->status)->toBe('pending');
    });

    it('lets the signed-in device decline', function () {
        $user = User::factory()->create();
        [$creator, $desktop] = qrCreatorStarts($user);

        qrBrowser();
        $this->post(route('qr.claim', $creator['token']));
        $phone = session()->all();

        qrBrowser(session: $desktop);
        $this->actingAs($user);
        $this->postJson(route('qr.deny', $creator['token']))->assertOk()->assertJsonPath('status', 'denied');
        $this->getJson(route('qr.status'))->assertJsonPath('status', 'denied')->assertJsonMissingPath('options');

        qrBrowser(session: $phone);
        $this->getJson(route('qr.status'))->assertJsonPath('status', 'denied');
        $this->postJson(route('qr.redeem'))->assertStatus(422);
        $this->assertGuest();
    });

    it('asks the approving device to re-authenticate and come back to the page', function () {
        $user = User::factory()->create();
        [$creator] = qrCreatorStarts($user);
        qrBrowser();
        $this->post(route('qr.claim', $creator['token']));

        qrBrowser($user, confirmedAt: Date::now()->subMinutes(12)->unix());
        session(['qr_login.created' => QrLogin::sole()->id]);

        $this->postJson(route('qr.approve', $creator['token']), ['number' => QrLogin::sole()->match_number])
            ->assertStatus(409)->assertJsonPath('reauthenticate', route('reauthenticate.show'));
        expect(session('url.intended'))->toBe(route('sign-in-another-device.edit'))
            ->and(QrLogin::sole()->status)->toBe('pending');
    });

    it('turns away a claim on an expired or used code', function () {
        $user = User::factory()->create();
        [$creator] = qrCreatorStarts($user);

        $this->travel(121)->seconds();

        qrBrowser();
        $this->post(route('qr.claim', $creator['token']))->assertSessionHas('qr_notice');
        $this->get(route('qr.show', $creator['token']))->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'taken'));
        expect(QrLogin::sole()->binding_hash)->toBeNull();
    });

    it('does not claim just because the code was opened', function () {
        $user = User::factory()->create();
        [$creator] = qrCreatorStarts($user);

        qrBrowser();
        $this->get(route('qr.show', $creator['token']))->assertOk();
        $this->get(route('qr.show', $creator['short_code']))->assertOk();

        expect(QrLogin::sole()->binding_hash)->toBeNull();
    });

    it('tells a signed-in user opening such a code that it is for another device', function () {
        $user = User::factory()->create();
        [$creator, $desktop] = qrCreatorStarts($user);

        qrBrowser(session: $desktop);
        $this->actingAs($user);
        $this->get(route('qr.show', $creator['token']))->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'owner'));
    });

    it('retires the earlier code when the device shows another', function () {
        $user = User::factory()->create();
        [$first, $desktop] = qrCreatorStarts($user);

        qrBrowser(session: $desktop);
        $this->actingAs($user);
        $this->postJson(route('sign-in-another-device.store'))->assertOk();

        expect(QrLogin::where('status', 'expired')->count())->toBe(1);

        qrBrowser();
        $this->post(route('qr.claim', $first['token']))->assertSessionHas('qr_notice');
    });

    it('does not report another user\'s code as the creator\'s own', function () {
        $user = User::factory()->create();
        [, $desktop] = qrCreatorStarts($user);

        qrBrowser(User::factory()->create(), session: $desktop);
        $this->getJson(route('qr.status'))->assertJsonPath('status', 'none');
    });

    it('keeps a signed-in browser out of the claim step', function () {
        [$data] = qrPcStarts();
        $user = User::factory()->create();
        qrBrowser($user);

        $this->post(route('qr.claim', $data['short_code']))->assertRedirect();
    });
});

describe('the admin switch', function () {
    it('is on by default and shown to admins only', function () {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.settings.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/Settings')->where('qrLoginEnabled', true));

        $this->actingAs(User::factory()->create())->get(route('admin.settings.edit'))->assertForbidden();
        $this->actingAs(User::factory()->create())->put(route('admin.settings.update'), ['qr_login_enabled' => '0'])->assertForbidden();
    });

    it('turns QR sign-in off and on again, and records who did it', function () {
        $admin = User::factory()->admin()->create();
        confirmSession();

        $this->actingAs($admin)->put(route('admin.settings.update'))->assertRedirect();
        expect(app(QrLoginPolicy::class)->enabled())->toBeFalse();
        $this->assertDatabaseHas('access_audits', ['action' => 'qr_login_disable', 'actor_id' => $admin->id]);

        $this->put(route('admin.settings.update'), ['qr_login_enabled' => '1'])->assertRedirect();
        expect(app(QrLoginPolicy::class)->enabled())->toBeTrue();
        $this->assertDatabaseHas('access_audits', ['action' => 'qr_login_enable', 'actor_id' => $admin->id]);
        expect(Setting::count())->toBe(1);
    });

    it('needs a recent confirmation', function () {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('admin.settings.update'), ['qr_login_enabled' => '0'])
            ->assertRedirect(route('reauthenticate.show'));
        expect(app(QrLoginPolicy::class)->enabled())->toBeTrue();
    });

    it('rejects a value that is not a boolean', function () {
        $admin = User::factory()->admin()->create();
        confirmSession();

        $this->actingAs($admin)->put(route('admin.settings.update'), ['qr_login_enabled' => 'maybe'])
            ->assertSessionHasErrors('qr_login_enabled');
    });
});
