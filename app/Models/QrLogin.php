<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\DeviceFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $token_hash
 * @property string $code_hash
 * @property string $direction
 * @property string $status
 * @property int $match_number
 * @property list<int> $decoy_numbers
 * @property string|null $binding_hash
 * @property string|null $requester_user_agent
 * @property string|null $requester_ip
 * @property string|null $requester_network
 * @property int|null $approver_user_id
 * @property Carbon|null $claimed_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $redeemed_at
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $approver
 */
#[Fillable([
    'token_hash', 'code_hash', 'direction', 'status', 'match_number', 'decoy_numbers', 'binding_hash',
    'requester_user_agent', 'requester_ip', 'requester_network', 'approver_user_id', 'claimed_at',
    'approved_at', 'redeemed_at', 'expires_at',
])]
#[Hidden(['token_hash', 'code_hash', 'binding_hash'])]
class QrLogin extends Model
{
    use MassPrunable;

    /** The signed-out device shows the QR and a signed-in phone approves it. */
    public const DIRECTION_A = 'a';

    /** A signed-in device shows the QR and a signed-out phone is signed in by it. */
    public const DIRECTION_B = 'b';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    public const REDEEMED = 'redeemed';

    public const EXPIRED = 'expired';

    public const RETENTION_HOURS = 24;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decoy_numbers' => 'array',
            'claimed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /**
     * Expiry is a clock, not a write, so a row nobody touched again still
     * reads as expired.
     */
    public function effectiveStatus(): string
    {
        $open = in_array($this->status, [self::PENDING, self::APPROVED], true);

        return $open && ! $this->expires_at->isFuture() ? self::EXPIRED : $this->status;
    }

    public function isClaimed(): bool
    {
        return $this->binding_hash !== null;
    }

    /**
     * The three numbers the approver chooses from, in ascending order so the
     * position of the right one gives nothing away.
     *
     * @return list<int>
     */
    public function numberOptions(): array
    {
        $options = [$this->match_number, ...$this->decoy_numbers];
        sort($options);

        return $options;
    }

    /**
     * What the approver is shown about the device asking to be signed in.
     *
     * @return array{device: string, ip: string|null, network: string|null, requested_at: string|null}
     */
    public function requesterDevice(DeviceFingerprint $fingerprints): array
    {
        return [
            'device' => $fingerprints->label($this->requester_user_agent),
            'ip' => $this->requester_ip,
            'network' => $this->requester_network,
            'requested_at' => ($this->claimed_at ?? $this->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', CarbonImmutable::now()->subHours(self::RETENTION_HOURS));
    }
}
