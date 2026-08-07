<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The scheduler on this droplet was never installed and nothing reported it:
 * every command was registered and none had ever run, while the app answered
 * HTTP perfectly and looked healthy from outside. This is the signal that would
 * have caught that.
 */
final class SchedulerHeartbeat
{
    private const KEY = 'scheduler.last_run_at';

    /**
     * Generous against a once-a-minute beat. The question is "has the scheduler
     * stopped", not "did one run slip", and a threshold that trips on a single
     * slow minute would be ignored within a week.
     */
    public const STALE_AFTER_MINUTES = 15;

    public function record(): void
    {
        // No TTL: an expired entry and a scheduler that never ran would look the
        // same, and those need different answers.
        Cache::forever(self::KEY, CarbonImmutable::now()->toIso8601String());
    }

    public function lastRunAt(): ?CarbonImmutable
    {
        $stored = Cache::get(self::KEY);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        return CarbonImmutable::parse($stored);
    }

    public function isStale(): bool
    {
        $lastRun = $this->lastRunAt();

        // Never having run is the worst case, not an unknown one.
        if ($lastRun === null) {
            return true;
        }

        return $lastRun->lt(CarbonImmutable::now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
