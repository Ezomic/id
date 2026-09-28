<?php

declare(strict_types=1);

namespace App\Actions\Estate;

use App\Models\Application;
use App\Models\ApplicationUser;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * @phpstan-type EstateApplication array{slug: string, name: string, active: bool, client_id: string|null, redirect_hosts: list<string>, created_at: string|null}
 * @phpstan-type EstateGrant array{user: string|null, application: string|null, via: string, group: string|null, granted_at: string|null}
 */
class ReadEstate
{
    /**
     * Every registered application and every grant of access to one. Group
     * grants are listed alongside direct ones, because a reader that only saw
     * application_user would conclude that nobody in a group can reach
     * anything.
     *
     * @return array{applications: list<EstateApplication>, grants: list<EstateGrant>}
     */
    public function handle(): array
    {
        $applications = Application::query()->with('oauthClient')->orderBy('slug')->get();

        $grants = [...$this->directGrants($applications), ...$this->groupGrants()];

        usort($grants, fn (array $a, array $b): int => [$a['application'], $a['user'], $a['via'], $a['group']]
            <=> [$b['application'], $b['user'], $b['via'], $b['group']]);

        return [
            'applications' => array_values($applications->map($this->application(...))->all()),
            'grants' => $grants,
        ];
    }

    /**
     * @return EstateApplication
     */
    private function application(Application $application): array
    {
        return [
            'slug' => $application->slug,
            'name' => $application->name,
            'active' => $application->active,
            'client_id' => $application->oauth_client_id,
            'redirect_hosts' => $this->hosts($application->oauthClient === null ? [] : $application->oauthClient->redirect_uris),
            'created_at' => $application->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $uris
     * @return list<string>
     */
    private function hosts(array $uris): array
    {
        $hosts = array_map(fn (string $uri): mixed => parse_url($uri, PHP_URL_HOST), $uris);

        return array_values(array_unique(array_filter($hosts, 'is_string')));
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @return list<EstateGrant>
     */
    private function directGrants(Collection $applications): array
    {
        $slugs = $applications->pluck('slug', 'id');
        $emails = User::query()->pluck('email', 'id');

        return array_values(ApplicationUser::query()->get()->map(fn (ApplicationUser $grant): array => [
            'user' => $this->stringOrNull($emails->get($grant->user_id)),
            'application' => $this->stringOrNull($slugs->get($grant->application_id)),
            'via' => 'direct',
            'group' => null,
            'granted_at' => $grant->created_at?->toIso8601String(),
        ])->all());
    }

    /**
     * Group membership and group access carry no timestamps, so there is no
     * grant date to report for these.
     *
     * @return list<EstateGrant>
     */
    private function groupGrants(): array
    {
        $grants = [];

        foreach (Group::query()->with(['applications', 'users'])->get() as $group) {
            foreach ($group->applications as $application) {
                foreach ($group->users as $user) {
                    $grants[] = [
                        'user' => $user->email,
                        'application' => $application->slug,
                        'via' => 'group',
                        'group' => $group->slug,
                        'granted_at' => null,
                    ];
                }
            }
        }

        return $grants;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
