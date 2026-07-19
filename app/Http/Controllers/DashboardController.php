<?php

namespace App\Http\Controllers;

use App\Models\Application;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $accessibleIds = $user->applications()->pluck('applications.id')->all();

        $applications = Application::where('active', true)
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
                'can_access' => in_array($app->id, $accessibleIds, true),
            ]);

        return Inertia::render('Dashboard', [
            'applications' => $applications->values(),
            'accessibleCount' => count($accessibleIds),
        ]);
    }
}
