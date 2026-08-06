<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
    public const MAX_ATTEMPTS = 5;

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
