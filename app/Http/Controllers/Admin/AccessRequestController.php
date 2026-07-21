<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Notifications\AccessRequestDecided;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccessRequestController extends Controller
{
    public function index(): Response
    {
        $requests = AccessRequest::query()
            ->pending()
            ->with(['user:id,name,email', 'application:id,name'])
            ->latest()
            ->get()
            ->map(fn (AccessRequest $request): array => [
                'id' => $request->id,
                'user' => ['name' => $request->user->name, 'email' => $request->user->email],
                'application' => $request->application->name,
                'requested_at_diff' => $request->created_at?->diffForHumans(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/AccessRequests', ['requests' => $requests]);
    }

    public function approve(Request $request, AccessRequest $accessRequest): RedirectResponse
    {
        if ($accessRequest->status !== 'pending') {
            return back();
        }

        $accessRequest->user->applications()->syncWithoutDetaching([$accessRequest->application_id]);
        $this->decide($accessRequest, 'approved', $request->user()->id);
        $accessRequest->user->notify(new AccessRequestDecided($accessRequest->application->name, true));

        return back()->with('status', 'Access granted.');
    }

    public function deny(Request $request, AccessRequest $accessRequest): RedirectResponse
    {
        if ($accessRequest->status !== 'pending') {
            return back();
        }

        $this->decide($accessRequest, 'denied', $request->user()->id);
        $accessRequest->user->notify(new AccessRequestDecided($accessRequest->application->name, false));

        return back()->with('status', 'Request denied.');
    }

    private function decide(AccessRequest $accessRequest, string $status, int $adminId): void
    {
        $accessRequest->update([
            'status' => $status,
            'decided_by_id' => $adminId,
            'decided_at' => now(),
        ]);
    }
}
