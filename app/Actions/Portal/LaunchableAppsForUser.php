<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Models\Application;
use App\Models\User;

class LaunchableAppsForUser
{
    /**
     * The active applications a user may open from an app switcher: those they
     * can access that expose a launch URL.
     *
     * @return list<array{slug: string, name: string, initials: string, accent: string|null, launch_url: string}>
     */
    public function handle(User $user): array
    {
        $applications = Application::query()
            ->whereIn('id', $user->accessibleApplicationIds())
            ->where('active', true)
            ->whereNotNull('launch_url')
            ->orderBy('name')
            ->get();

        $apps = [];

        foreach ($applications as $application) {
            if ($application->launch_url === null) {
                continue;
            }

            $apps[] = [
                'slug' => $application->slug,
                'name' => $application->name,
                'initials' => $application->glyph(),
                'accent' => $application->accent,
                'launch_url' => $application->launch_url,
            ];
        }

        return $apps;
    }
}
