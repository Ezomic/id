<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Which OAuth clients a given ID session has signed the user in to. Without this
 * there is no way to know who to tell when that session logs out.
 *
 * @property int $id
 * @property int $user_id
 * @property string $sso_session_id
 * @property string $oauth_client_id
 */
#[Fillable(['user_id', 'sso_session_id', 'oauth_client_id'])]
class AuthorizedClient extends Model
{
    use MassPrunable;

    /**
     * Rows are deleted eagerly when a session logs out, so what accumulates here
     * is sessions that were simply abandoned. There is no join back to the
     * sessions table (sso_session_id is an opaque value held inside the session
     * payload, not the framework session id), so age is the available signal.
     *
     * Pruning is on updated_at rather than created_at: re-authorizing an app
     * touches the row, so a session in daily use never ages out.
     */
    public const RETENTION_DAYS = 30;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('updated_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
