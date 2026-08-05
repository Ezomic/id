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
                'ip_address' => $event->ip_address,
                'device' => $fingerprints->label($event->user_agent),
                'application' => $event->application,
                'created_at_diff' => $event->created_at?->diffForHumans(),
            ])
            ->values()
            ->all();

        return Inertia::render('settings/SignInHistory', ['events' => $events]);
    }
}
