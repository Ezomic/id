<?php

declare(strict_types=1);

namespace App\Actions\Access;

use App\Models\Application;
use App\Models\User;
use Carbon\CarbonInterface;

final class ConnectedApplications
{
    /**
     * Which apps are currently holding credentials on this user's behalf.
     *
     * Passport records no last-used timestamp, but a refresh mints a new access
     * token, so the newest row is the closest thing to recent activity and the
     * oldest is when the connection started.
     *
     * @return list<array{id: int|null, name: string, slug: string|null, connected_since: string|null, last_token_at: string|null, expires_at: string|null, tokens: int}>
     */
    public function handle(User $user): array
    {
        $tokens = $user->tokens()->get();

        if ($tokens->isEmpty()) {
            return [];
        }

        $applications = Application::query()
            ->whereIn('oauth_client_id', $tokens->pluck('client_id')->unique()->values())
            ->get()
            ->keyBy(fn (Application $application): string => (string) $application->oauth_client_id);

        /** @var array<string, array{first: CarbonInterface|null, last: CarbonInterface|null, expires: CarbonInterface|null, count: int}> $byClient */
        $byClient = [];

        foreach ($tokens as $token) {
            $clientId = (string) $token->client_id;
            $created = $token->created_at;
            $expires = $token->expires_at;

            $existing = $byClient[$clientId] ?? ['first' => $created, 'last' => $created, 'expires' => $expires, 'count' => 0];

            $byClient[$clientId] = [
                'first' => $this->earliest($existing['first'], $created),
                'last' => $this->latest($existing['last'], $created),
                'expires' => $this->latest($existing['expires'], $expires),
                'count' => $existing['count'] + 1,
            ];
        }

        $rows = [];

        foreach ($byClient as $clientId => $summary) {
            $application = $applications->get($clientId);

            $rows[] = [
                'id' => $application?->id,
                // A client with no Application row is an app that was deleted
                // from ID while its tokens were still live.
                'name' => $application->name ?? 'Unknown application',
                'slug' => $application?->slug,
                'connected_since' => $summary['first']?->diffForHumans(),
                'last_token_at' => $summary['last']?->diffForHumans(),
                'expires_at' => $summary['expires']?->diffForHumans(),
                'tokens' => $summary['count'],
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    private function earliest(?CarbonInterface $a, ?CarbonInterface $b): ?CarbonInterface
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->lessThan($b) ? $a : $b;
    }

    private function latest(?CarbonInterface $a, ?CarbonInterface $b): ?CarbonInterface
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->greaterThan($b) ? $a : $b;
    }
}
