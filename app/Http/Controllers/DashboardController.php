<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Bookmark;
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

        $bookmarks = $user->bookmarks()
            ->active()
            ->latest()
            ->get()
            ->map(fn (Bookmark $bookmark) => [
                'id' => $bookmark->id,
                'url' => $bookmark->url,
                'title' => $bookmark->title ?? $bookmark->domain,
                'domain' => $bookmark->domain,
                'image' => $bookmark->image,
            ]);

        return Inertia::render('Dashboard', [
            'applications' => $applications->values(),
            'accessibleCount' => count($accessibleIds),
            'bookmarks' => $bookmarks->values(),
        ]);
    }
}
