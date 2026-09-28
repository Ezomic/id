<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Access\RevokeClientTokens;
use App\Actions\Access\RevokeTokensForLostAccess;
use App\Models\AccessAudit;
use App\Models\Application;
use App\Models\User;

class UpdateApplication
{
    public function __construct(
        private readonly RevokeClientTokens $revokeClientTokens,
        private readonly RevokeTokensForLostAccess $revokeTokensForLostAccess,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Application $application, array $data): void
    {
        $wasActive = $application->active;
        $isActive = (bool) ($data['active'] ?? false);

        $application->forceFill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'initials' => $data['initials'] ?? null,
            'accent' => $data['accent'] ?? null,
            'launch_url' => $data['launch_url'] ?? null,
            'category' => $data['category'] ?? null,
            'active' => $isActive,
        ])->save();

        if (array_key_exists('allowed_scopes', $data)) {
            $scopes = $data['allowed_scopes'];

            $application->forceFill([
                'allowed_scopes' => is_array($scopes) ? array_values($scopes) : null,
            ])->save();
        }

        if (isset($data['redirect_uri']) && $application->oauthClient) {
            $application->oauthClient->forceFill([
                'redirect_uris' => [$data['redirect_uri']],
            ])->save();
        }

        if ($wasActive !== $isActive) {
            $this->syncClientState($application, $isActive);
        }

        if (array_key_exists('users', $data)) {
            $this->syncUsers($application, array_values(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($data['users'] ?? null) ? $data['users'] : [])));
        }
    }

    /**
     * Taking someone off the list here is the same revoke as doing it from
     * their user page, so the app has to hear about it the same way.
     *
     * @param  list<int>  $userIds
     */
    private function syncUsers(Application $application, array $userIds): void
    {
        $removed = $application->users()
            ->whereNotIn('users.id', $userIds)
            ->get()
            ->map(fn (User $user): array => [$user, $user->accessibleApplicationIds()->all()])
            ->all();

        $application->users()->sync($userIds);

        foreach ($removed as [$user, $reachableBefore]) {
            $this->revokeTokensForLostAccess->handle($user, $reachableBefore);
        }
    }

    /**
     * `active` used to be cosmetic on the ID side. Mirroring it onto the OAuth
     * client's revoked flag is what makes deactivating an app actually stop the
     * authorize and token endpoints from serving it.
     */
    private function syncClientState(Application $application, bool $isActive): void
    {
        $client = $application->oauthClient;

        if ($client !== null) {
            $client->forceFill(['revoked' => ! $isActive])->save();

            if (! $isActive) {
                $this->revokeClientTokens->handle($client);
            }
        }

        AccessAudit::log($isActive ? 'app_enable' : 'app_disable', ['application_id' => $application->id]);
    }
}
