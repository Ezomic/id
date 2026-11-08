<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\QrLoginRejected;
use App\Models\QrLogin;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Date;

/**
 * Every rule about when a QR login may be created, answered, approved or
 * redeemed lives here, so no action can quietly apply a weaker one.
 */
final class QrLoginPolicy
{
    public const SETTING = 'qr_login_enabled';

    public const TTL_SECONDS = 120;

    /**
     * Shorter than the 15 minutes admin actions allow: approving a sign-in
     * hands out a whole session, so the check has to be fresher.
     */
    public const REAUTH_WINDOW_MINUTES = 10;

    private const SESSION_REQUESTER = 'qr_login.requester';

    private const SESSION_CREATED = 'qr_login.created';

    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 8;

    private const TOKEN_LENGTH = 43;

    public function enabled(): bool
    {
        return Setting::flag(self::SETTING, true);
    }

    /**
     * @throws QrLoginRejected
     */
    public function ensureEnabled(): void
    {
        if (! $this->enabled()) {
            throw QrLoginRejected::because(QrLoginRejected::DISABLED);
        }
    }

    /**
     * @throws QrLoginRejected
     */
    public function ensureOpen(QrLogin $qrLogin): void
    {
        match ($qrLogin->effectiveStatus()) {
            QrLogin::PENDING => null,
            QrLogin::EXPIRED => throw QrLoginRejected::because(QrLoginRejected::EXPIRED),
            default => throw QrLoginRejected::because(QrLoginRejected::USED),
        };
    }

    /**
     * @throws QrLoginRejected
     */
    public function ensureRecentlyConfirmed(mixed $confirmedAt): void
    {
        if (! $this->recentlyConfirmed($confirmedAt)) {
            throw QrLoginRejected::because(QrLoginRejected::REAUTH_REQUIRED);
        }
    }

    public function recentlyConfirmed(mixed $confirmedAt): bool
    {
        return (is_int($confirmedAt) || is_numeric($confirmedAt))
            && Date::now()->unix() - (int) $confirmedAt < self::REAUTH_WINDOW_MINUTES * 60;
    }

    /**
     * @throws QrLoginRejected
     */
    public function ensureNumberMatches(QrLogin $qrLogin, int $chosen): void
    {
        if (! hash_equals((string) $qrLogin->match_number, (string) $chosen)) {
            throw QrLoginRejected::because(QrLoginRejected::WRONG_NUMBER);
        }
    }

    /**
     * Direction B is answered only by the user whose device showed the code,
     * and only once a device has opened it.
     *
     * @throws QrLoginRejected
     */
    public function ensureMayAnswer(QrLogin $qrLogin, User $user): void
    {
        if ($qrLogin->direction === QrLogin::DIRECTION_A) {
            return;
        }

        if ($qrLogin->approver_user_id !== $user->id) {
            throw QrLoginRejected::because(QrLoginRejected::FORBIDDEN);
        }

        if (! $qrLogin->isClaimed()) {
            throw QrLoginRejected::because(QrLoginRejected::NOT_CLAIMED);
        }
    }

    public function hash(string $credential): string
    {
        return hash_hmac('sha256', $credential, $this->key());
    }

    public function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function newShortCode(): string
    {
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $code;
    }

    public function formatShortCode(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /**
     * Resolves the long token from a QR or the short code typed by hand. The
     * lookup is by keyed hash and the hit is compared again in constant time.
     */
    public function find(string $credential): ?QrLogin
    {
        $isToken = strlen($credential) === self::TOKEN_LENGTH;
        $column = $isToken ? 'token_hash' : 'code_hash';
        $hash = $this->hash($isToken ? $credential : $this->normaliseShortCode($credential));

        $qrLogin = QrLogin::query()->where($column, $hash)->first();

        if ($qrLogin === null) {
            return null;
        }

        $stored = $isToken ? $qrLogin->token_hash : $qrLogin->code_hash;

        return hash_equals($stored, $hash) ? $qrLogin : null;
    }

    public function normaliseShortCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /**
     * @return array{0: int, 1: list<int>}
     */
    public function newNumbers(): array
    {
        $numbers = range(10, 99);
        shuffle($numbers);

        return [$numbers[0], [$numbers[1], $numbers[2]]];
    }

    public function bindingHash(string $secret): string
    {
        return $this->hash('binding|'.$secret);
    }

    public function rememberRequester(Session $session, QrLogin $qrLogin, string $secret): void
    {
        $session->put(self::SESSION_REQUESTER, ['id' => $qrLogin->id, 'secret' => $secret]);
    }

    /**
     * The request this browser started, found only through the secret that
     * never left its session. A URL or a QR image alone cannot produce it.
     */
    public function requesterFor(Session $session): ?QrLogin
    {
        $held = $session->get(self::SESSION_REQUESTER);

        if (! is_array($held) || ! is_int($held['id'] ?? null) || ! is_string($held['secret'] ?? null)) {
            return null;
        }

        $qrLogin = QrLogin::query()->find($held['id']);

        if ($qrLogin === null || $qrLogin->binding_hash === null) {
            return null;
        }

        return hash_equals($qrLogin->binding_hash, $this->bindingHash($held['secret'])) ? $qrLogin : null;
    }

    public function rememberCreated(Session $session, QrLogin $qrLogin): void
    {
        $session->put(self::SESSION_CREATED, $qrLogin->id);
    }

    public function createdBy(Session $session, User $user): ?QrLogin
    {
        $id = $session->get(self::SESSION_CREATED);

        if (! is_int($id)) {
            return null;
        }

        return QrLogin::query()->whereKey($id)->where('approver_user_id', $user->id)->first();
    }

    public function forgetAll(Session $session): void
    {
        $session->forget([self::SESSION_REQUESTER, self::SESSION_CREATED]);
    }

    private function key(): string
    {
        $key = config('app.key');

        return is_string($key) ? $key : '';
    }
}
