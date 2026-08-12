<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A device the user has said is theirs, so ID-46 stops telling them about it.
 *
 * Trust is about the device, never the session. Revoking a session leaves the
 * device trusted, and revoking trust leaves the session signed in: they are
 * different statements and collapsing them would surprise in both directions.
 *
 * @property int $id
 * @property int $user_id
 * @property string $device_fingerprint
 * @property string $label
 * @property Carbon $expires_at
 */
#[Fillable(['user_id', 'device_fingerprint', 'label', 'expires_at'])]
class TrustedDevice extends Model
{
    use MassPrunable;

    /**
     * Trust lapses so a device that was yours a year ago, and may since have
     * been sold or stolen, does not stay silent forever.
     */
    public const TRUST_DAYS = 90;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now());
    }
}
