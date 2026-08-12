<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Models\SignInEvent;
use App\Services\DeviceFingerprint;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SignInHistoryController extends Controller
{
    use InteractsWithCurrentUser;

    public function index(Request $request, DeviceFingerprint $fingerprints): Response
    {
        $events = SignInEvent::query()
            ->where('user_id', $this->currentUser($request)->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (SignInEvent $event): array => [
                'id' => $event->id,
                'method' => $event->method,
                'outcome' => $event->outcome,
                'ip_address' => $event->ip_address,
                'device' => $fingerprints->label($event->user_agent),
                'application' => $event->application,
                'created_at_diff' => $event->created_at?->diffForHumans(),
            ])
            ->values()
            ->all();

        $user = $this->currentUser($request);
        $current = $fingerprints->forUserAgent($request->userAgent());

        return Inertia::render('settings/SignInHistory', [
            'events' => $events,
            'currentDeviceTrusted' => $user->trusts($current),
            'trustedDevices' => $user->trustedDevices()
                ->where('expires_at', '>', now())
                ->get()
                ->map(fn ($device): array => [
                    'id' => $device->id,
                    'label' => $device->label,
                    'expires_diff' => $device->expires_at->diffForHumans(),
                    'is_current' => $device->device_fingerprint === $current,
                ])
                ->values()
                ->all(),
        ]);
    }
}
