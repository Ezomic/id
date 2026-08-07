<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Auth\DeliverLogoutNotification;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\LogoutNotification;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LogoutDeliveryController extends Controller
{
    public function index(): Response
    {
        $pending = LogoutNotification::query()
            ->whereNull('delivered_at')
            ->with(['application:id,name,slug'])
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (LogoutNotification $notification): array => [
                'id' => $notification->id,
                'application' => $this->applicationName($notification),
                'endpoint' => $notification->endpoint,
                'attempts' => $notification->attempts,
                'last_error' => $notification->last_error,
                // Abandoned rows are the ones that need a human: nothing will
                // pick them up again on its own.
                'abandoned' => $notification->attempts >= LogoutNotification::MAX_ATTEMPTS,
                'age' => $notification->created_at?->diffForHumans(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/LogoutDeliveries', [
            'pending' => $pending,
            'maxAttempts' => LogoutNotification::MAX_ATTEMPTS,
        ]);
    }

    /**
     * The foreign key cascades, so in practice a notification cannot outlive its
     * application, but the relation is still nullable until it is loaded.
     */
    private function applicationName(LogoutNotification $notification): string
    {
        $application = $notification->application;

        return $application instanceof Application ? $application->name : 'Unknown application';
    }

    /**
     * Clears the attempt count so the scheduled retry picks it up again. Used
     * after the consumer has been fixed, since otherwise an abandoned logout is
     * owed forever with nothing that will ever deliver it.
     */
    public function retry(LogoutNotification $logoutNotification, DeliverLogoutNotification $deliver): RedirectResponse
    {
        if ($logoutNotification->delivered_at !== null) {
            return back()->with('status', 'That logout was already delivered.');
        }

        $logoutNotification->forceFill(['attempts' => 0, 'last_error' => null])->save();

        $deliver->handle($logoutNotification->fresh() ?? $logoutNotification);

        return back()->with('status', 'Retried.');
    }
}
