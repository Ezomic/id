<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Access\ConnectedApplications;
use App\Actions\Access\RevokeTokensForLostAccess;
use App\Actions\Access\SignOutEverywhere;
use App\Actions\Admin\CreateUser;
use App\Actions\Admin\SetApplicationAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateAccessRequest;
use App\Models\AccessAudit;
use App\Models\Application;
use App\Models\User;
use App\Services\DeviceFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
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
                ]),
            'applications' => Application::orderBy('name')->get(['id', 'name', 'slug', 'active']),
        ]);
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

    public function store(StoreUserRequest $request, CreateUser $createUser): RedirectResponse
    {
        $createUser->handle($request->validated());

        return back()->with('status', 'User created.');
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
