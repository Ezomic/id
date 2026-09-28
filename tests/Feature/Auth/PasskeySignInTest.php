<?php

declare(strict_types=1);

use App\Models\User;
use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

const SOFTWARE_CREDENTIAL_ID = 'software-authenticator';

/**
 * A user with a passkey held by a software authenticator: a real P-256 key
 * pair, stored the way a registration ceremony would store its public half.
 *
 * @return array{0: User, 1: OpenSSLAsymmetricKey}
 */
function passkeyHolder(): array
{
    $user = User::factory()->create();

    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $point = openssl_pkey_get_details($key)['ec'];

    // COSE_Key: EC2, ES256, P-256, then the x and y coordinates.
    $publicKey = (string) MapObject::create()
        ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
        ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
        ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
        ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($point['x'], 32, "\0", STR_PAD_LEFT)))
        ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($point['y'], 32, "\0", STR_PAD_LEFT)));

    $record = CredentialRecord::create(
        publicKeyCredentialId: SOFTWARE_CREDENTIAL_ID,
        type: 'public-key',
        transports: [],
        attestationType: 'none',
        trustPath: EmptyTrustPath::create(),
        aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
        credentialPublicKey: $publicKey,
        userHandle: $user->getPasskeyUserHandle(),
        counter: 0,
    );

    $user->passkeys()->create([
        'name' => 'Laptop',
        'credential_id' => Base64UrlSafe::encodeUnpadded(SOFTWARE_CREDENTIAL_ID),
        'credential' => json_decode(WebAuthn::toJson($record), true, flags: JSON_THROW_ON_ERROR),
    ]);

    return [$user, $key];
}

/**
 * What the browser does: fetch a challenge, have the authenticator sign it,
 * and post the assertion the way the passkey client does.
 *
 * @param  array<string, mixed>  $extra
 */
function signInWithPasskey(User $user, OpenSSLAsymmetricKey $key, array $extra = []): TestResponse
{
    $challenge = test()->getJson(route('passkey.login-options'))->assertOk()->json('options.challenge');

    $clientData = json_encode([
        'type' => 'webauthn.get',
        'challenge' => $challenge,
        'origin' => config('app.url'),
        'crossOrigin' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    // RP ID hash, flags (user present and verified), signature counter.
    $authenticatorData = hash('sha256', config('passkeys.relying_party_id'), true)."\x05".pack('N', 1);

    openssl_sign($authenticatorData.hash('sha256', $clientData, true), $signature, $key, OPENSSL_ALGO_SHA256);

    return test()->postJson(route('passkey.login'), [
        'credential' => [
            'id' => Base64UrlSafe::encodeUnpadded(SOFTWARE_CREDENTIAL_ID),
            'rawId' => Base64UrlSafe::encodeUnpadded(SOFTWARE_CREDENTIAL_ID),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authenticatorData),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => Base64UrlSafe::encodeUnpadded($user->getPasskeyUserHandle()),
            ],
        ],
        ...$extra,
    ]);
}

it('remembers the device after a passkey sign-in, whatever the client sends', function (array $extra) {
    [$user, $key] = passkeyHolder();

    signInWithPasskey($user, $key, $extra)
        ->assertOk()
        ->assertCookie(Auth::guard('web')->getRecallerName());

    $this->assertAuthenticatedAs($user);
})->with([
    'remember left out' => [[]],
    'remember turned off' => [['remember' => false]],
]);

it('hands the passkey client the page the sign-in began at', function () {
    [$user, $key] = passkeyHolder();

    $authorize = url('/oauth/authorize?client_id=tracker&response_type=code');

    $this->withSession(['url.intended' => $authorize]);

    signInWithPasskey($user, $key)
        ->assertOk()
        ->assertExactJson(['redirect' => $authorize]);
});
