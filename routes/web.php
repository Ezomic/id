<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginCodeController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::post('login/code', [LoginCodeController::class, 'send'])
        ->middleware('throttle:login')
        ->name('login.code.send');

    Route::post('login/code/verify', [LoginCodeController::class, 'verify'])
        ->middleware('throttle:login')
        ->name('login.code.verify');
});

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}/access', [UserController::class, 'updateAccess'])->name('users.access.update');
});

require __DIR__.'/settings.php';
