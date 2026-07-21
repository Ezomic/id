<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $method
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $application
 * @property string $device_fingerprint
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'method', 'ip_address', 'user_agent', 'application', 'device_fingerprint'])]
class SignInEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
