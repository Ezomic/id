<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Access\RevokeUserTokens;
use App\Models\Application;
use App\Models\AuthorizedClient;
use App\Models\LogoutNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class NotifyClientsOfLogout
{
    public function __construct(
        private readonly RevokeUserTokens $revokeUserTokens,
        private readonly DeliverLogoutNotification $deliver,
    ) {}

    /**
     * Tear down a user's presence at the consumer apps: revoke the credentials
     * and tell each app to end its local session.
     *
     * The scope differs by caller, which is why this is not keyed on the current
     * request. A user signing themselves out ends one ID session, so only the
     * clients that session authorized are affected. An admin forcing a sign-out
     * means every session, and the acting request belongs to the admin, so there
     * is no current session to read.
     *
     * @param  string|null  $ssoSessionId  Null covers every session this user has.
     * @return list<int> The notification ids created.
     */
    public function handle(User $user, ?string $ssoSessionId = null): array
    {
        $authorizations = AuthorizedClient::query()
            ->where('user_id', $user->id)
            ->when($ssoSessionId !== null, fn ($query) => $query->where('sso_session_id', $ssoSessionId));

        $clientIds = (clone $authorizations)->pluck('oauth_client_id')->unique()->values()->all();

        $authorizations->delete();

        if ($clientIds === []) {
            return [];
        }

        $applications = Application::query()->whereIn('oauth_client_id', $clientIds)->get();

        // The credentials go too. A consumer that misses the notification would
        // otherwise still hold a usable refresh token.
        $this->revokeUserTokens->handle(
            $user,
            array_values($applications->map(fn (Application $a): int => $a->id)->all()),
        );

        return $this->queue($user, $applications);
    }

    /**
     * Delivered after the response, so a slow consumer never shows up as a slow
     * logout. A terminating callback rather than a queued job: nothing needs
     * serialising, and there is no worker running in production.
     *
     * @param  list<int>  $ids
     */
    public function deliverAfterResponse(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        app()->terminating(function () use ($ids): void {
            $pending = LogoutNotification::query()->whereIn('id', $ids)->with('application')->get();

            foreach ($pending as $notification) {
                $this->deliver->handle($notification);
            }
        });
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @return list<int>
     */
    private function queue(User $user, Collection $applications): array
    {
        $ids = [];

        foreach ($applications as $application) {
            $endpoint = $application->logoutUrl();

            // Apps that do not follow the id-client convention have nowhere to
            // send this; their tokens are still revoked above.
            if ($endpoint === null) {
                continue;
            }

            $ids[] = LogoutNotification::create([
                'user_id' => $user->id,
                'application_id' => $application->id,
                'event' => LogoutNotification::EVENT_LOGOUT,
                'endpoint' => $endpoint,
            ])->id;
        }

        return $ids;
    }
}
