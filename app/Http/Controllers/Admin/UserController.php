<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Access\ConnectedApplications;
use App\Actions\Access\RevokeTokensForLostAccess;
use App\Actions\Access\SignOutEverywhere;
use App\Actions\Admin\CreateUser;
use App\Actions\Admin\InviteUser;
use App\Actions\Admin\SetAdminRole;
use App\Actions\Admin\SetApplicationAccess;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateAccessRequest;
use App\Models\AccessAudit;
use App\Models\Application;
use App\Models\User;
use App\Services\DeviceFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class UserController extends Controller
{
    use InteractsWithCurrentUser;

    public function index(): Response
    {
        return Inertia::render('admin/Users', [
            'users' => User::with('applications:id')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => $user->is_admin,
                    'application_ids' => $user->applications->pluck('id'),
                    'invited' => $user->invitation_token !== null,
                    'accepted' => $user->invitation_accepted_at !== null,
                ]),
            'applications' => Application::orderBy('name')->get(['id', 'name', 'slug', 'active']),
            'adminCount' => User::where('is_admin', true)->count(),
        ]);
    }

    public function updateRole(User $user, SetAdminRole $setAdminRole): RedirectResponse
    {
        try {
            $setAdminRole->handle($user, ! $user->is_admin);
        } catch (RuntimeException $e) {
            return back()->withErrors(['is_admin' => $e->getMessage()]);
        }

        return back()->with('status', 'Role updated.');
    }

    public function show(
        User $user,
        ConnectedApplications $connectedApplications,
        DeviceFingerprint $fingerprints,
    ): Response {
        return Inertia::render('admin/UserDetail', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
            ],
            'sessions' => DB::table('sessions')
                ->where('user_id', $user->id)
                ->orderByDesc('last_activity')
                ->get()
                ->map(fn (object $session): array => [
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'device' => $fingerprints->label(is_string($session->user_agent) ? $session->user_agent : null),
                    'last_active_diff' => CarbonImmutable::createFromTimestamp(is_numeric($session->last_activity) ? (int) $session->last_activity : 0)->diffForHumans(),
                ])
                ->values()
                ->all(),
            'connections' => $connectedApplications->handle($user),
        ]);
    }

    public function signOutEverywhere(User $user, SignOutEverywhere $signOut): RedirectResponse
    {
        $signOut->handle($user);

        return back()->with('status', 'Signed out everywhere and revoked all tokens.');
    }

    public function store(StoreUserRequest $request, CreateUser $createUser, InviteUser $inviteUser): RedirectResponse
    {
        $user = $createUser->handle($request->validated());

        // Scripted setup should not send mail, so inviting is opt-in rather
        // than something creating a user always does.
        if ($request->boolean('invite')) {
            $inviteUser->handle($user, $this->currentUser($request));

            return back()->with('status', 'User created and invited.');
        }

        return back()->with('status', 'User created.');
    }

    public function invite(Request $request, User $user, InviteUser $inviteUser): RedirectResponse
    {
        $inviteUser->handle($user, $this->currentUser($request));

        return back()->with('status', 'Invitation sent.');
    }

    public function updateAccess(UpdateAccessRequest $request, User $user, SetApplicationAccess $setAccess, RevokeTokensForLostAccess $revokeTokens): RedirectResponse
    {
        $before = array_values(array_map(
            fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $user->applications()->pluck('applications.id')->all(),
        ));
        $after = $request->collect('applications')
            ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->values()
            ->all();

        $setAccess->handle($user, $after);

        foreach (array_diff($after, $before) as $applicationId) {
            AccessAudit::log('grant', ['subject_user_id' => $user->id, 'application_id' => $applicationId]);
        }

        foreach (array_diff($before, $after) as $applicationId) {
            AccessAudit::log('revoke', ['subject_user_id' => $user->id, 'application_id' => $applicationId]);
        }

        $revokeTokens->handle($user->fresh() ?? $user);

        return back()->with('status', 'Access updated.');
    }
}
