<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * ID tokens are signed with the same keypair Passport already uses for access
 * tokens, so a client that trusts one trusts the other and there is no second
 * key to rotate or distribute.
 */
final class OidcKeys
{
    /**
     * @return non-empty-string
     */
    public function privateKey(): string
    {
        $key = $this->read('oauth-private.key', 'private_key');

        return $key === '' ? 'missing-private-key' : $key;
    }

    public function publicKey(): string
    {
        return $this->read('oauth-public.key', 'public_key');
    }

    /**
     * Derived from the key itself, so it changes when the key does and a cached
     * JWKS cannot silently verify against the wrong one.
     */
    public function keyId(): string
    {
        return substr(hash('sha256', $this->publicKey()), 0, 16);
    }

    /**
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        $jwk = $this->publicJwk();

        $n = $jwk['n'] ?? null;
        $e = $jwk['e'] ?? null;

        if (! is_string($n) || ! is_string($e)) {
            return ['keys' => []];
        }

        return [
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $this->keyId(),
                'n' => $n,
                'e' => $e,
            ]],
        ];
    }

    /**
     * phpseclib can emit the public key as a JWK set directly, which avoids
     * hand-rolling the big-integer to base64url conversion.
     *
     * @return array<mixed>
     */
    private function publicJwk(): array
    {
        $public = $this->publicKey();

        if ($public === '') {
            return [];
        }

        $encoded = PublicKeyLoader::load($public)->toString('JWK');

        if (! is_string($encoded)) {
            return [];
        }

        $decoded = json_decode($encoded, true);

        if (! is_array($decoded)) {
            return [];
        }

        $keys = $decoded['keys'] ?? null;
        $first = is_array($keys) ? ($keys[0] ?? null) : null;

        return is_array($first) ? $first : [];
    }

    private function read(string $filename, string $configKey): string
    {
        $configured = config("passport.{$configKey}");

        if (is_string($configured) && $configured !== '') {
            return Str::replace('\\n', "\n", $configured);
        }

        $path = Passport::keyPath($filename);

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }
}
