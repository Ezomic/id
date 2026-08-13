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
 * @property string|null $logout_secret
 * @property Carbon|null $typed_events_confirmed_at
 * @property list<string>|null $allowed_scopes
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OAuthClient|null $oauthClient
 */
#[Fillable(['name', 'slug', 'description', 'initials', 'accent', 'launch_url', 'category', 'oauth_client_id', 'logout_secret', 'allowed_scopes', 'active'])]
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
        return $this->siblingOfCallback('/auth/sso/redirect');
    }

    /**
     * Where to POST a back-channel logout. Same convention as ssoLaunchUrl():
     * apps using the id-client package register a `/auth/sso/callback` redirect
     * URI and get the sibling logout endpoint for free.
     */
    public function logoutUrl(): ?string
    {
        return $this->siblingOfCallback('/auth/sso/logout');
    }

    /**
     * Both endpoints are derived from the same registered callback, because an
     * app that launches on one host and receives back-channel calls on another
     * is not one integration. Picking independently is how zero ended up
     * launching on zero.thijssensoftware.nl while its logouts went to a
     * hostname with no DNS record at all. See ID-78.
     */
    private function siblingOfCallback(string $path): ?string
    {
        $suffix = '/auth/sso/callback';

        $callbacks = array_values(array_filter(
            $this->redirectUris(),
            fn (string $uri) => str_ends_with($uri, $suffix),
        ));

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

        $chosen ??= $callbacks[0];

        return substr($chosen, 0, -strlen($suffix)).$path;
    }

    public function glyph(): string
    {
        return $this->initials ?: Str::of($this->name)->trim()->substr(0, 1)->upper()->value();
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'allowed_scopes' => 'array',
            'typed_events_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Whether this consumer reads the `event` field on a back-channel call.
     *
     * id-client 0.2 does not: it verifies the signature and ends the session
     * whatever the event says. Sending it anything other than a sign-out
     * therefore signs the user out for the wrong reason, so ID has to know
     * which behaviour it is talking to before it can use the newer events.
     */
    public function understandsTypedEvents(): bool
    {
        return $this->typed_events_confirmed_at !== null;
    }

    /**
     * Scopes registered before this feature existed are null, which means the
     * application predates scoping and still gets everything. Opting one in is
     * a deliberate act, because narrowing it is what can break a live consumer.
     */
    public function scopesAreEnforced(): bool
    {
        return $this->allowed_scopes !== null;
    }

    public function allowsScope(string $scope): bool
    {
        return ! $this->scopesAreEnforced() || in_array($scope, $this->allowed_scopes ?? [], true);
    }

    /**
     * What a token from this application is allowed to read. An unscoped
     * application reads everything, which is exactly what it does today.
     */
    public function grantsScope(string $scope, ?string $tokenScopes): bool
    {
        if (! $this->scopesAreEnforced()) {
            return true;
        }

        return $this->allowsScope($scope)
            && in_array($scope, preg_split('/\s+/', (string) $tokenScopes, -1, PREG_SPLIT_NO_EMPTY) ?: [], true);
    }
}
