<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ApplicationUser;
use App\Models\Bookmark;
use App\Services\StatusReader;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        /** @var Collection<int, ApplicationUser> $pivots */
        $pivots = ApplicationUser::where('user_id', $user->id)->get()->keyBy('application_id');
        $statuses = app(StatusReader::class)->statesBySlug();

        $applications = Application::where('active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Application $app) use ($pivots, $statuses) {
                $pivot = $pivots->get($app->id);

                return [
                    'id' => $app->id,
                    'name' => $app->name,
                    'slug' => $app->slug,
                    'description' => $app->description,
                    'initials' => $app->glyph(),
                    'accent' => $app->accent,
                    'launch_url' => $app->launch_url,
                    'can_access' => $pivots->has($app->id),
                    'pinned' => (bool) $pivot?->pinned,
                    'position' => $pivot?->position,
                    'status' => $statuses[strtolower($app->slug)] ?? null,
                ];
            });

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
                'favicon' => $bookmark->favicon,
                'pinned' => $bookmark->pinned,
                'position' => $bookmark->position,
            ]);

        // Pinned first, then the user's saved order (unordered items last), keeping the
        // incoming name/recency order as the tiebreak. Chained stable sorts, applied
        // least-significant first, since the multi-key array form does not take closures.
        $pinFirst = fn (array $item): int => $item['pinned'] ? 0 : 1;
        $byPosition = fn (array $item): int => $item['position'] ?? PHP_INT_MAX;

        return Inertia::render('Dashboard', [
            'applications' => $applications->sortBy($byPosition)->sortBy($pinFirst)->values(),
            'accessibleCount' => $pivots->count(),
            'bookmarks' => $bookmarks->sortBy($byPosition)->sortBy($pinFirst)->values(),
            'recentApps' => $this->recentApps($pivots),
        ]);
    }

    /**
     * The last few apps the user launched, most recent first.
     *
     * @param  Collection<int, ApplicationUser>  $pivots
     * @return array<int, array<string, mixed>>
     */
    private function recentApps(Collection $pivots): array
    {
        $recentIds = $pivots
            ->filter(fn (ApplicationUser $pivot) => $pivot->last_launched_at !== null)
            ->sortByDesc(fn (ApplicationUser $pivot) => $pivot->last_launched_at)
            ->take(4)
            ->keys();

        if ($recentIds->isEmpty()) {
            return [];
        }

        $apps = Application::whereKey($recentIds)->get()->keyBy('id');

        return $recentIds
            ->map(fn (int $id) => $apps->get($id))
            ->filter()
            ->map(fn (Application $app) => [
                'id' => $app->id,
                'name' => $app->name,
                'initials' => $app->glyph(),
                'accent' => $app->accent,
                'launch_url' => $app->launch_url,
            ])
            ->values()
            ->all();
    }
}
