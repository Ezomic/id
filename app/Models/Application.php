<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $initials
 * @property string|null $accent
 * @property string|null $launch_url
 * @property string|null $oauth_client_id
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OAuthClient|null $oauthClient
 */
#[Fillable(['name', 'slug', 'description', 'initials', 'accent', 'launch_url', 'oauth_client_id', 'active'])]
class Application extends Model
{
    /**
     * The users allowed to access this application.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** @return BelongsToMany<Group, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }

    /**
     * The OAuth client that backs this application.
     *
     * @return BelongsTo<OAuthClient, $this>
     */
    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'oauth_client_id');
    }

    public function redirectUri(): ?string
    {
        return $this->oauthClient?->redirect_uris[0] ?? null;
    }

    public function glyph(): string
    {
        return $this->initials ?: Str::of($this->name)->trim()->substr(0, 1)->upper()->value();
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
