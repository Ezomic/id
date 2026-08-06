<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
class AuthorizedClient extends Model {}
