<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per switcher lookup. ID-33 decided any first-party client may ask;
 * this is what makes the asking answerable after the fact.
 *
 * @property int $id
 * @property string $oauth_client_id
 * @property string $subject_email
 * @property bool $matched
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 */
#[Fillable(['oauth_client_id', 'subject_email', 'matched', 'ip_address'])]
class PortalLookup extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const RETENTION_DAYS = 90;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['matched' => 'boolean'];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
