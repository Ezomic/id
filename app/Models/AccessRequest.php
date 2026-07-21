<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $application_id
 * @property string $status
 * @property int|null $decided_by_id
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'application_id', 'status', 'decided_by_id', 'decided_at'])]
class AccessRequest extends Model
{
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    /**
     * @param  Builder<AccessRequest>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }
}
