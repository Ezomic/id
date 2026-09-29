<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

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
     * is browsers that were simply abandoned. There is no join back to the
     * sessions table (sso_session_id is an opaque value the browser holds, not
     * the framework session id), so age is the available signal.
     *
     * The age that counts is the remember-me lifetime, from the browser's last
     * sign-in to the app. id-client signs in with remember-me, so an app never
     * comes back through authorize while its own cookie holds, and a shorter
     * window forgets apps that are still signed in. The apps keep Laravel's
     * default lifetime, as ID does.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('updated_at', '<', now()->subMinutes(Config::integer('auth.guards.web.remember', 576000)));
    }
}
