<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Application;
use App\Models\AuthorizedClient;
use App\Models\LogoutNotification;
use App\Models\User;

/**
 * ID-48 built signed, retried, audited delivery and used it for exactly one
 * event. Meanwhile a consumer keeps a local copy of the user written once at
 * the OAuth callback and never updated, so a name or email changed here stays
 * stale in all seven apps indefinitely.
 *
 * Same pipeline, different event. Older clients ignore types they do not know,
 * so a consumer that has not been redeployed keeps working.
 */
final class NotifyClientsOfEvent
{
    public function __construct(private readonly DeliverLogoutNotification $deliver) {}

    /**
     * @param  list<int>|null  $applicationIds  Null means every app holding a token.
     * @param  array<string, mixed>  $payload
     */
    public function handle(User $user, string $event, ?array $applicationIds = null, array $payload = []): void
    {
        $clientIds = AuthorizedClient::query()
            ->where('user_id', $user->id)
            ->pluck('oauth_client_id')
            ->unique()
            ->values()
            ->all();

        if ($clientIds === []) {
            return;
        }

        $applications = Application::query()
            ->whereIn('oauth_client_id', $clientIds)
            ->when($applicationIds !== null, fn ($query) => $query->whereIn('id', $applicationIds ?? []))
            ->get();

        $ids = [];

        foreach ($applications as $application) {
            $endpoint = $application->logoutUrl();

            if ($endpoint === null) {
                continue;
            }

            $ids[] = LogoutNotification::create([
                'user_id' => $user->id,
                'application_id' => $application->id,
                'event' => $event,
                'payload' => $payload === [] ? null : $payload,
                'endpoint' => $endpoint,
            ])->id;
        }

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
}
