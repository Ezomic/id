<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PortalController extends Controller
{
    /**
     * Send the user to an app, recording the launch so it can surface in the
     * recently-used row. Going through id rather than linking straight out is
     * what makes that row possible.
     */
    public function launch(Request $request, Application $application): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canAccess($application) && $application->launch_url !== null, 403);

        $user->applications()->updateExistingPivot($application->id, [
            'last_launched_at' => Carbon::now(),
        ]);

        return redirect()->away($application->launch_url);
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:app,bookmark'],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $user = $request->user();

        foreach ($data['ids'] as $position => $id) {
            if ($data['type'] === 'app') {
                $user->applications()->updateExistingPivot((int) $id, ['position' => $position]);

                continue;
            }

            $user->bookmarks()->whereKey($id)->update(['position' => $position]);
        }

        return back();
    }

    public function pin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:app,bookmark'],
            'id' => ['required', 'integer'],
            'pinned' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        if ($data['type'] === 'app') {
            $user->applications()->updateExistingPivot($data['id'], ['pinned' => $data['pinned']]);

            return back();
        }

        $user->bookmarks()->whereKey($data['id'])->update(['pinned' => $data['pinned']]);

        return back();
    }
}
