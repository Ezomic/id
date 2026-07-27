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
 * @property string|null $category
 * @property string|null $oauth_client_id
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OAuthClient|null $oauthClient
 */
#[Fillable(['name', 'slug', 'description', 'initials', 'accent', 'launch_url', 'category', 'oauth_client_id', 'active'])]
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

    /**
     * The OAuth redirect URIs registered for this app, or an empty list when
     * it has no backing client.
     *
     * @return list<string>
     */
    private function redirectUris(): array
    {
        $client = $this->oauthClient;

        return $client === null ? [] : $client->redirect_uris;
    }

    /**
     * Where to send a user to *launch* the app signed in. Apps that use the
     * id-client package register a `/auth/sso/callback` redirect URI; hitting
     * the sibling `/auth/sso/redirect` starts the OAuth flow, which completes
     * silently against the user's existing ID session. Returns null for apps
     * that don't follow that convention (they're launched via launch_url).
     */
    public function ssoLaunchUrl(): ?string
    {
        $suffix = '/auth/sso/callback';

        $callbacks = array_filter(
            $this->redirectUris(),
            fn (string $uri) => str_ends_with($uri, $suffix),
        );

        if ($callbacks === []) {
            return null;
        }

        $launchHost = $this->launch_url === null ? null : parse_url($this->launch_url, PHP_URL_HOST);

        $chosen = null;

        foreach ($callbacks as $uri) {
            if ($launchHost !== null && parse_url($uri, PHP_URL_HOST) === $launchHost) {
                $chosen = $uri;
                break;
            }
        }

        $chosen ??= reset($callbacks);

        return substr($chosen, 0, -strlen($suffix)).'/auth/sso/redirect';
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
