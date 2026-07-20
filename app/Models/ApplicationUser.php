<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $application_id
 * @property int $user_id
 * @property bool $pinned
 * @property int|null $position
 * @property Carbon|null $last_launched_at
 */
class ApplicationUser extends Pivot
{
    protected $table = 'application_user';

    public $incrementing = true;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
            'position' => 'integer',
            'last_launched_at' => 'datetime',
        ];
    }
}
