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
        $client = $this->clients->createAuthorizationCodeGrantClient(
            name: $data['name'],
            redirectUris: [$data['redirect_uri']],
            confidential: true,
        );

        $application = Application::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'initials' => $data['initials'] ?? null,
            'accent' => $data['accent'] ?? null,
            'launch_url' => $data['launch_url'] ?? null,
            'oauth_client_id' => $client->getKey(),
            'active' => $data['active'] ?? true,
        ]);

        $application->users()->sync($data['users'] ?? []);

        return [
            'application' => $application,
            'client_id' => (string) $client->getKey(),
            'client_secret' => (string) $client->plainSecret,
        ];
    }
}
