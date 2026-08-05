<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $method
 * @property string $outcome
 * @property string|null $ip_address
 * @property string|null $network
 * @property string|null $user_agent
 * @property string|null $application
 * @property string $device_fingerprint
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'method', 'outcome', 'ip_address', 'network', 'user_agent', 'application', 'device_fingerprint'])]
class SignInEvent extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const SUCCESS = 'success';

    public const FAILURE = 'failure';

    /**
     * The history page only ever reads back the most recent 50 rows, so keeping
     * anything beyond a sane investigation window is dead weight.
     */
    public const RETENTION_DAYS = 180;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', CarbonImmutable::now()->subDays(self::RETENTION_DAYS));
    }
}
