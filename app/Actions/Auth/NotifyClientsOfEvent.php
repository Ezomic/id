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
 * Same pipeline, different event. What older clients do with an unfamiliar
 * event is the catch: id-client 0.2 never reads the field and ends the session
 * on anything it accepts, so an event that does not mean "sign out" is withheld
 * until the consumer has been observed reading it. See ID-77.
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
        $legacySafe = LogoutNotification::isSafeForLegacyClients($event);

        foreach ($applications as $application) {
            $endpoint = $application->logoutUrl();

            if ($endpoint === null) {
                continue;
            }

            // Withholding the event leaves the consumer's copy of the user
            // stale, which is the bug ID-73 set out to fix. Delivering it
            // signs the user out of an app they are working in. Stale loses to
            // spurious sign-outs, and the connection check says which apps are
            // in this state and what to redeploy.
            if (! $legacySafe && ! $application->understandsTypedEvents()) {
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
