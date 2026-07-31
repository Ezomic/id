<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Models\Application;
use App\Models\User;

class LaunchableAppsForUser
{
    /**
     * The active applications a user may open from an app switcher, split into
     * the uncategorized main apps and grouped categories (e.g. Games), mirroring
     * the in-app portal. Only apps the user can access that expose a launch URL
     * are included.
     *
     * @return array{applications: list<array{slug: string, name: string, initials: string, accent: string|null, launch_url: string}>, categories: list<array{category: string, apps: list<array{slug: string, name: string, initials: string, accent: string|null, launch_url: string}>}>}
     */
    public function handle(User $user): array
    {
        $applications = Application::query()
            ->whereIn('id', $user->accessibleApplicationIds())
            ->where('active', true)
            ->whereNotNull('launch_url')
            ->orderBy('name')
            ->get();

        $main = [];

        /** @var array<string, list<array{slug: string, name: string, initials: string, accent: string|null, launch_url: string}>> $grouped */
        $grouped = [];

        foreach ($applications as $application) {
            if ($application->launch_url === null) {
                continue;
            }

            $entry = [
                'slug' => $application->slug,
                'name' => $application->name,
                'initials' => $application->glyph(),
                'accent' => $application->accent,
                'launch_url' => $application->launch_url,
            ];

            if ($application->category === null) {
                $main[] = $entry;

                continue;
            }

            $grouped[$application->category][] = $entry;
        }

        ksort($grouped);

        $categories = [];

        foreach ($grouped as $category => $apps) {
            $categories[] = ['category' => $category, 'apps' => $apps];
        }

        return ['applications' => $main, 'categories' => $categories];
    }
}
