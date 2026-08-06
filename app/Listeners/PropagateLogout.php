<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Access\RevokeUserTokens;
use App\Actions\Auth\DeliverLogoutNotification;
use App\Models\Application;
use App\Models\AuthorizedClient;
use App\Models\LogoutNotification;
use App\Models\User;
use App\Services\SsoSessionId;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class PropagateLogout
{
    public function __construct(
        private readonly Request $request,
        private readonly SsoSessionId $ssoSessionId,
        private readonly RevokeUserTokens $revokeUserTokens,
        private readonly DeliverLogoutNotification $deliver,
    ) {}

    /**
     * Signing out of ID used to end the ID session and nothing else: each
     * consumer holds its own session, established once at the OAuth callback,
     * and never asks ID anything again. Tell them.
     */
    public function handle(Logout $event): void
    {
        $user = $event->user;
        $sessionId = $this->ssoSessionId->existing($this->request);

        if (! $user instanceof User || $sessionId === null) {
            return;
        }

        $clientIds = AuthorizedClient::query()
            ->where('sso_session_id', $sessionId)
            ->where('user_id', $user->id)
            ->pluck('oauth_client_id')
            ->all();

        AuthorizedClient::query()->where('sso_session_id', $sessionId)->delete();

        if ($clientIds === []) {
            return;
        }

        // The credentials go too. A consumer that misses the notification would
        // otherwise still hold a usable refresh token.
        $applications = Application::query()->whereIn('oauth_client_id', $clientIds)->get();

        $this->revokeUserTokens->handle(
            $user,
            array_values($applications->map(fn (Application $a): int => $a->id)->all()),
        );

        $ids = $this->queue($user, $applications);

        if ($ids === []) {
            return;
        }

        // Delivered after the response, so a slow consumer never shows up as a
        // slow logout. A terminating callback rather than a queued job: nothing
        // needs serialising, and there is no worker running in production.
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
                'endpoint' => $endpoint,
            ])->id;
        }

        return $ids;
    }
}
