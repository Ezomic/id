<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SchedulerHeartbeat;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SchedulerHealthController extends Controller
{
    /**
     * Deliberately separate from /up. The app answering requests and the
     * scheduler running are different failures with different fixes, and
     * collapsing them would either hide this one or take the site down over it.
     */
    public function __invoke(SchedulerHeartbeat $heartbeat): JsonResponse
    {
        $stale = $heartbeat->isStale();
        $lastRun = $heartbeat->lastRunAt();

        return response()->json([
            'healthy' => ! $stale,
            'last_run_at' => $lastRun?->toIso8601String(),
            'stale_after_minutes' => SchedulerHeartbeat::STALE_AFTER_MINUTES,
        ], $stale ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK);
    }
}
