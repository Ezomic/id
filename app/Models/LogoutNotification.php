<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pending "this user logged out" call to a consumer app. Persisted rather than
 * fired and forgotten so an unreachable consumer can be retried on the schedule
 * instead of silently keeping the user signed in.
 *
 * @property int $id
 * @property int $user_id
 * @property int $application_id
 * @property string $endpoint
 * @property int $attempts
 * @property Carbon|null $delivered_at
 * @property string|null $last_error
 * @property-read Application|null $application
 */
#[Fillable(['user_id', 'application_id', 'endpoint', 'attempts', 'delivered_at', 'last_error'])]
class LogoutNotification extends Model
{
    use MassPrunable;

    public const MAX_ATTEMPTS = 5;

    /**
     * A delivered notification has done its job and is only ever read back in
     * aggregate.
     */
    public const DELIVERED_RETENTION_DAYS = 30;

    /**
     * One that gave up is evidence a consumer is broken, so it outlives the
     * successes. See the admin view in ID-60.
     */
    public const ABANDONED_RETENTION_DAYS = 90;

    /**
     * Rows still being retried are never pruned, however old: dropping one
     * would silently give up on a logout that is still owed to a consumer.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where(fn (Builder $query) => $query
                ->whereNotNull('delivered_at')
                ->where('delivered_at', '<', now()->subDays(self::DELIVERED_RETENTION_DAYS)))
            ->orWhere(fn (Builder $query) => $query
                ->whereNull('delivered_at')
                ->where('attempts', '>=', self::MAX_ATTEMPTS)
                ->where('updated_at', '<', now()->subDays(self::ABANDONED_RETENTION_DAYS)));
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['delivered_at' => 'datetime', 'attempts' => 'integer'];
    }
}
