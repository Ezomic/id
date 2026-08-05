<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SignInEvent;
use App\Services\DeviceFingerprint;
use Inertia\Inertia;
use Inertia\Response;

class FailedSignInController extends Controller
{
    public function index(DeviceFingerprint $fingerprints): Response
    {
        $attempts = SignInEvent::query()
            ->where('outcome', SignInEvent::FAILURE)
            ->with('user:id,name,email')
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (SignInEvent $event): array => [
                'id' => $event->id,
                'method' => $event->method,
                'ip_address' => $event->ip_address,
                'device' => $fingerprints->label($event->user_agent),
                // Null means the attempt was against an address with no account.
                // Nothing about the attempted address is stored, deliberately.
                'account' => $event->user?->email,
                'created_at_diff' => $event->created_at?->diffForHumans(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/FailedSignIns', ['attempts' => $attempts]);
    }
}
