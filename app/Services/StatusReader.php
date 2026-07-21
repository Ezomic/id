<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class StatusReader
{
    /**
     * Current state keyed by (lower-cased) service slug, read from the Status
     * app's machine-readable endpoint (STAT-6). Cached briefly and fails open:
     * any error or missing config yields an empty map, so the portal renders
     * without dots rather than breaking.
     *
     * @return array<string, string>
     */
    public function statesBySlug(): array
    {
        $url = config('services.status.url');
        $token = config('services.status.token');

        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            return [];
        }

        return Cache::remember('portal-service-status', now()->addSeconds(30), function () use ($url, $token): array {
            try {
                $response = Http::timeout(3)->withToken($token)->acceptJson()->get($url);

                if (! $response->successful()) {
                    return [];
                }

                $states = [];

                foreach ((array) $response->json('services', []) as $service) {
                    $slug = $service['slug'] ?? null;
                    $state = $service['state'] ?? null;

                    if (is_string($slug) && is_string($state)) {
                        $states[strtolower($slug)] = $state;
                    }
                }

                return $states;
            } catch (Throwable) {
                return [];
            }
        });
    }
}
