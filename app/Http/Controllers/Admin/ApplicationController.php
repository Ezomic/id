<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\CreateApplication;
use App\Actions\Admin\UpdateApplication;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreApplicationRequest;
use App\Http\Requests\Admin\UpdateApplicationRequest;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Applications', [
            'applications' => Application::with(['oauthClient', 'users:id'])
                ->orderBy('name')
                ->get()
                ->map(fn (Application $app) => [
                    'id' => $app->id,
                    'name' => $app->name,
                    'slug' => $app->slug,
                    'description' => $app->description,
                    'initials' => $app->glyph(),
                    'accent' => $app->accent,
                    'launch_url' => $app->launch_url,
                    'redirect_uri' => $app->redirectUri(),
                    'client_id' => $app->oauth_client_id,
                    'active' => $app->active,
                    'user_ids' => $app->users->pluck('id'),
                    'users_count' => $app->users->count(),
                ]),
            'users' => User::orderBy('name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'initials' => Str::of($user->name)->explode(' ')->take(2)->map(fn ($p) => Str::substr($p, 0, 1))->implode(''),
            ]),
        ]);
    }

    public function store(StoreApplicationRequest $request, CreateApplication $createApplication): RedirectResponse
    {
        $result = $createApplication->handle($request->validated());

        return back()->with('createdClient', [
            'name' => $result['application']->name,
            'client_id' => $result['client_id'],
            'client_secret' => $result['client_secret'],
        ]);
    }

    public function update(UpdateApplicationRequest $request, Application $application, UpdateApplication $updateApplication): RedirectResponse
    {
        $updateApplication->handle($application, $request->validated());

        return back()->with('status', 'Application saved.');
    }
}
