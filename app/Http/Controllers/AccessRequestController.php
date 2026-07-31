<?php

namespace App\Http\Controllers;

use App\Concerns\InteractsWithCurrentUser;
use App\Models\AccessRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccessRequestController extends Controller
{
    use InteractsWithCurrentUser;

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'application_id' => ['required', Rule::exists('applications', 'id')->where('active', true)],
        ]);

        $user = $this->currentUser($request);
        $applicationId = $request->integer('application_id');

        if ($user->applications()->whereKey($applicationId)->exists()) {
            return back()->with('status', 'You already have access to this app.');
        }

        // One open request per user + app — a repeat click doesn't stack up.
        AccessRequest::firstOrCreate([
            'user_id' => $user->id,
            'application_id' => $applicationId,
            'status' => 'pending',
        ]);

        return back()->with('status', 'Access requested. An admin will review it.');
    }
}
