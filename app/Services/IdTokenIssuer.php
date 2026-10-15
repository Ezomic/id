<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

final class IdTokenIssuer
{
    public function __construct(private readonly OidcKeys $keys) {}

    /**
     * @return non-empty-string
     */
    private function issuer(): string
    {
        $url = config('app.url');
        $issuer = is_string($url) ? rtrim($url, '/') : '';

        return $issuer === '' ? 'thijssensoftware-id' : $issuer;
    }

    public const LIFETIME_MINUTES = 15;

    /**
     * A standard OIDC ID token, signed with the same key as the access token.
     *
     * Claims follow the scopes the token was actually granted, so an app with
     * only `identity` does not learn the estate through a different door than
     * the one ID-70 closed on userinfo.
     *
     * `auth_time` is when the user last re-entered a credential, present only
     * when the app asked for a `max_age` and ID verified it.
     *
     * @param  list<string>  $scopes
     */
    public function issue(User $user, string $audience, array $scopes, ?string $nonce = null, ?int $authTime = null): string
    {
        $now = CarbonImmutable::now();

        $builder = (new Builder(new JoseEncoder, ChainedFormatter::default()))
            ->issuedBy($this->issuer())
            ->permittedFor($audience === '' ? 'unknown-client' : $audience)
            ->relatedTo((string) $user->id)
            ->issuedAt($now->toDateTimeImmutable())
            ->expiresAt($now->addMinutes(self::LIFETIME_MINUTES)->toDateTimeImmutable())
            ->withHeader('kid', $this->keys->keyId());

        if (in_array('identity', $scopes, true)) {
            $builder = $builder
                ->withClaim('name', $user->name)
                ->withClaim('email', $user->email)
                ->withClaim('email_verified', $user->email_verified_at !== null);
        }

        // Binds the token to the authorize request that asked for it, which is
        // what stops one being replayed into a different sign-in.
        if ($nonce !== null && $nonce !== '') {
            $builder = $builder->withClaim('nonce', $nonce);
        }

        if ($authTime !== null) {
            $builder = $builder->withClaim('auth_time', $authTime);
        }

        return $builder
            ->getToken(new Sha256, InMemory::plainText($this->keys->privateKey()))
            ->toString();
    }
}
