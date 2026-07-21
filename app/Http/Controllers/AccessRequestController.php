<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccessRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'application_id' => ['required', Rule::exists('applications', 'id')->where('active', true)],
        ]);

        $user = $request->user();
        $applicationId = (int) $data['application_id'];

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
