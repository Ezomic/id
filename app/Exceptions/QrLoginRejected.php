<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class QrLoginRejected extends RuntimeException
{
    public const DISABLED = 'disabled';

    public const EXPIRED = 'expired';

    public const USED = 'used';

    public const WRONG_NUMBER = 'wrong_number';

    public const REAUTH_REQUIRED = 'reauth_required';

    public const NOT_BOUND = 'not_bound';

    public const NOT_APPROVED = 'not_approved';

    public const FORBIDDEN = 'forbidden';

    public const NOT_CLAIMED = 'not_claimed';

    public const ALREADY_CLAIMED = 'already_claimed';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return match ($this->reason) {
            self::DISABLED => 404,
            self::EXPIRED => 410,
            self::REAUTH_REQUIRED => 409,
            self::NOT_BOUND, self::FORBIDDEN => 403,
            default => 422,
        };
    }

    public static function because(string $reason): self
    {
        return new self($reason, match ($reason) {
            self::DISABLED => 'Signing in with a QR code is turned off.',
            self::EXPIRED => 'This QR code has expired. Start again for a fresh one.',
            self::USED => 'This QR code has already been used or cancelled.',
            self::WRONG_NUMBER => 'That was not the number shown on the other device, so the request was cancelled.',
            self::REAUTH_REQUIRED => 'Confirm it is you again before approving a sign-in.',
            self::NOT_BOUND => 'This browser did not start that request.',
            self::NOT_APPROVED => 'The request has not been approved.',
            self::NOT_CLAIMED => 'No device has opened this code yet.',
            self::ALREADY_CLAIMED => 'Another device has already opened this code.',
            default => 'This request is not yours to answer.',
        });
    }
}
