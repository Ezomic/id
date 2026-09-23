<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Application;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

class CreateApplication
{
    public function __construct(private readonly ClientRepository $clients) {}

    /**
     * Register a workflow app as a confidential auth-code + PKCE OAuth client.
     *
     * @param  array<string, mixed>  $data
     * @return array{application: Application, client_id: string, client_secret: string, logout_secret: string}
     */
    public function handle(array $data): array
    {
        $name = is_string($data['name'] ?? null) ? $data['name'] : '';
        $redirectUri = is_string($data['redirect_uri'] ?? null) ? $data['redirect_uri'] : '';

        $client = $this->clients->createAuthorizationCodeGrantClient(
            name: $name,
            redirectUris: [$redirectUri],
            confidential: true,
        );

        // Passport's auth-code helper grants only authorization_code and
        // refresh_token, but a workflow app also calls ID machine-to-machine to
        // populate its portal switcher. Without client_credentials that request
        // returns unauthorized_client, and because IdPortalClient fails soft the
        // switcher silently renders "No other apps available" with nothing in
        // any log to explain it.
        $grants = $client->getAttribute('grant_types');
        $grants = is_array($grants) ? $grants : [];

        $client->forceFill([
            'grant_types' => [...$grants, 'client_credentials'],
        ])->save();

        $application = Application::create([
            'name' => $name,
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'initials' => $data['initials'] ?? null,
            'accent' => $data['accent'] ?? null,
            'launch_url' => $this->launchUrl($data, $redirectUri),
            'category' => $data['category'] ?? null,
            'oauth_client_id' => $client->getKey(),
            'logout_secret' => Str::random(64),
            'active' => $data['active'] ?? true,
        ]);

        $application->users()->sync($this->intList($data['users'] ?? []));

        return [
            'application' => $application,
            'client_id' => is_scalar($client->getKey()) ? (string) $client->getKey() : '',
            'client_secret' => is_string($client->plainSecret) ? $client->plainSecret : '',
            'logout_secret' => (string) $application->logout_secret,
        ];
    }

    /**
     * An application with a null launch_url cannot be launched from the portal
     * at all: PortalController refuses one, so the tile is inert and the admin
     * auto-grant that runs during SSO never gets the chance to fire. `id:app`
     * has no launch_url argument, which left every app it registered in that
     * state. Derive one from the callback instead. See ID-80.
     *
     * @param  array<string, mixed>  $data
     */
    private function launchUrl(array $data, string $redirectUri): ?string
    {
        $explicit = $data['launch_url'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return $this->origin($redirectUri);
    }

    /**
     * The scheme and authority of the callback, which for every app using the
     * id-client package is where the app itself lives.
     */
    private function origin(string $uri): ?string
    {
        $parts = parse_url($uri);

        if ($parts === false) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        $port = $parts['port'] ?? null;

        return $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');
    }

    /**
     * @return array<int, int>
     */
    private function intList(mixed $values): array
    {
        return array_values(array_map(
            fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            is_array($values) ? $values : [],
        ));
    }
}
