<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Application;
use Laravel\Passport\ClientRepository;

class CreateApplication
{
    public function __construct(private readonly ClientRepository $clients) {}

    /**
     * Register a workflow app as a confidential auth-code + PKCE OAuth client.
     *
     * @param  array<string, mixed>  $data
     * @return array{application: Application, client_id: string, client_secret: string}
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
            'launch_url' => $data['launch_url'] ?? null,
            'category' => $data['category'] ?? null,
            'oauth_client_id' => $client->getKey(),
            'active' => $data['active'] ?? true,
        ]);

        $application->users()->sync($this->intList($data['users'] ?? []));

        return [
            'application' => $application,
            'client_id' => is_scalar($client->getKey()) ? (string) $client->getKey() : '',
            'client_secret' => is_string($client->plainSecret) ? $client->plainSecret : '',
        ];
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
