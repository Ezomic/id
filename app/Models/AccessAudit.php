<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * @property int $id
 * @property int|null $actor_id
 * @property int|null $subject_user_id
 * @property int|null $application_id
 * @property int|null $group_id
 * @property string $action
 * @property Carbon|null $created_at
 * @property-read User|null $actor
 * @property-read User|null $subjectUser
 * @property-read Application|null $application
 * @property-read Group|null $group
 */
#[Fillable(['actor_id', 'subject_user_id', 'application_id', 'group_id', 'action'])]
class AccessAudit extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /**
     * Deliberately long. This is the record of who granted whom access to what,
     * which is the thing you want when reconstructing an incident months later,
     * and it grows slowly enough that keeping it is cheap.
     */
    public const RETENTION_DAYS = 730;

    /**
     * Record an access change, stamped with the acting admin.
     *
     * @param  array<string, int|null>  $attributes
     */
    public static function log(string $action, array $attributes): void
    {
        static::create([...$attributes, 'action' => $action, 'actor_id' => Auth::id()]);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
