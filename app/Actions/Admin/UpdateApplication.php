<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Application;

class UpdateApplication
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Application $application, array $data): void
    {
        $application->forceFill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'initials' => $data['initials'] ?? null,
            'accent' => $data['accent'] ?? null,
            'launch_url' => $data['launch_url'] ?? null,
            'active' => $data['active'] ?? false,
        ])->save();

        if (isset($data['redirect_uri']) && $application->oauthClient) {
            $application->oauthClient->forceFill([
                'redirect_uris' => [$data['redirect_uri']],
            ])->save();
        }

        if (array_key_exists('users', $data)) {
            $application->users()->sync($data['users'] ?? []);
        }
    }
}
