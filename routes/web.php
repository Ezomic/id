<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\Admin\AccessRequestController as AdminAccessRequestController;
use App\Http\Controllers\Admin\AccessAuditController;
use App\Http\Controllers\Admin\ApplicationController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginCodeController;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PortalController;
use App\Models\Application;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Inertia\Inertia::render('Welcome', [
        'applications' => Application::where('active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Application $app) => [
                'name' => $app->name,
                'slug' => $app->slug,
                'initials' => $app->glyph(),
                'accent' => $app->accent,
            ]),
    ]);
})->name('home');

Route::middleware('guest')->group(function () {
    Route::post('login/code', [LoginCodeController::class, 'send'])
        ->middleware('throttle:login')
        ->name('login.code.send');

    Route::post('login/code/verify', [LoginCodeController::class, 'verify'])
        ->middleware('throttle:login')
        ->name('login.code.verify');
});

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('portal/launch/{application}', [PortalController::class, 'launch'])->name('portal.launch');
    Route::patch('portal/order', [PortalController::class, 'reorder'])->name('portal.reorder');
    Route::patch('portal/pin', [PortalController::class, 'pin'])->name('portal.pin');

    Route::get('bookmarks', [BookmarkController::class, 'index'])->name('bookmarks.index');
    Route::post('bookmarks', [BookmarkController::class, 'store'])->name('bookmarks.store');
    Route::put('bookmarks/{bookmark}', [BookmarkController::class, 'update'])->name('bookmarks.update');
    Route::delete('bookmarks/{bookmark}', [BookmarkController::class, 'destroy'])->name('bookmarks.destroy');

    Route::post('access-requests', [AccessRequestController::class, 'store'])->name('access-requests.store');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}/access', [UserController::class, 'updateAccess'])->name('users.access.update');

    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::post('applications', [ApplicationController::class, 'store'])->name('applications.store');
    Route::put('applications/{application}', [ApplicationController::class, 'update'])->name('applications.update');

    Route::get('access-requests', [AdminAccessRequestController::class, 'index'])->name('access-requests.index');
    Route::post('access-requests/{accessRequest}/approve', [AdminAccessRequestController::class, 'approve'])->name('access-requests.approve');
    Route::post('access-requests/{accessRequest}/deny', [AdminAccessRequestController::class, 'deny'])->name('access-requests.deny');

    Route::get('groups', [GroupController::class, 'index'])->name('groups.index');
    Route::post('groups', [GroupController::class, 'store'])->name('groups.store');
    Route::put('groups/{group}', [GroupController::class, 'update'])->name('groups.update');
    Route::delete('groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');

    Route::get('access-audit', [AccessAuditController::class, 'index'])->name('access-audit.index');
});

require __DIR__.'/settings.php';
