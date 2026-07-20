<?php

namespace App\Http\Controllers;

use App\Actions\Bookmarks\SaveBookmark;
use App\Actions\Bookmarks\UpdateBookmark;
use App\Http\Requests\StoreBookmarkRequest;
use App\Http\Requests\UpdateBookmarkRequest;
use App\Models\Bookmark;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BookmarkController extends Controller
{
    public function index(Request $request): Response
    {
        $bookmarks = $request->user()->bookmarks()
            ->latest()
            ->get()
            ->map(fn (Bookmark $bookmark) => [
                'id' => $bookmark->id,
                'url' => $bookmark->url,
                'title' => $bookmark->title ?? $bookmark->domain,
                'domain' => $bookmark->domain,
                'image' => $bookmark->image,
                'note' => $bookmark->note,
                'tags' => $bookmark->tags ?? [],
                'read' => $bookmark->isRead(),
                'archived' => $bookmark->isArchived(),
                'saved_ago' => $bookmark->created_at?->diffForHumans(short: true),
            ]);

        return Inertia::render('Bookmarks', [
            'bookmarks' => $bookmarks->values(),
        ]);
    }

    public function store(StoreBookmarkRequest $request, SaveBookmark $saveBookmark): RedirectResponse
    {
        $saveBookmark->handle($request->user(), $request->validated());

        return back()->with('status', 'Link saved.');
    }

    public function update(UpdateBookmarkRequest $request, Bookmark $bookmark, UpdateBookmark $updateBookmark): RedirectResponse
    {
        Gate::authorize('update', $bookmark);

        $updateBookmark->handle($bookmark, $request->validated());

        return back()->with('status', 'Bookmark updated.');
    }

    public function destroy(Bookmark $bookmark): RedirectResponse
    {
        Gate::authorize('delete', $bookmark);

        $bookmark->delete();

        return back()->with('status', 'Bookmark deleted.');
    }
}
