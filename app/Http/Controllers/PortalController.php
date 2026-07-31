<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Concerns\InteractsWithCurrentUser;
use App\Models\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PortalController extends Controller
{
    use InteractsWithCurrentUser;

    /**
     * Send the user to an app, recording the launch so it can surface in the
     * recently-used row. Going through id rather than linking straight out is
     * what makes that row possible.
     */
    public function launch(Request $request, Application $application): RedirectResponse
    {
        $user = $this->currentUser($request);

        abort_unless($user->canAccess($application) && $application->launch_url !== null, 403);

        $user->applications()->updateExistingPivot($application->id, [
            'last_launched_at' => Carbon::now(),
        ]);

        // Prefer the app's SSO entry so the user arrives signed in; fall back
        // to the plain launch URL for apps that don't use id-client SSO.
        return redirect()->away($application->ssoLaunchUrl() ?? $application->launch_url);
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:app,bookmark'],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $user = $this->currentUser($request);

        $type = $request->string('type')->toString();

        foreach ($request->collect('ids')->values() as $position => $id) {
            $id = is_numeric($id) ? (int) $id : 0;

            if ($type === 'app') {
                $user->applications()->updateExistingPivot($id, ['position' => $position]);

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

        $user = $this->currentUser($request);

        $id = $request->integer('id');
        $pinned = $request->boolean('pinned');

        if ($request->string('type')->toString() === 'app') {
            $user->applications()->updateExistingPivot($id, ['pinned' => $pinned]);

            return back();
        }

        $user->bookmarks()->whereKey($id)->update(['pinned' => $pinned]);

        return back();
    }
}
