<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\CreateUser;
use App\Actions\Admin\SetApplicationAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateAccessRequest;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
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

    public function store(StoreUserRequest $request, CreateUser $createUser): RedirectResponse
    {
        $createUser->handle($request->validated());

        return back()->with('status', 'User created.');
    }

    public function updateAccess(UpdateAccessRequest $request, User $user, SetApplicationAccess $setAccess): RedirectResponse
    {
        $setAccess->handle($user, $request->validated()['applications'] ?? []);

        return back()->with('status', 'Access updated.');
    }
}
