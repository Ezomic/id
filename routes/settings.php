<?php

use App\Http\Controllers\Settings\ConnectionController;
use App\Http\Controllers\Settings\EmailChangeController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\RecoveryCodeController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SessionController;
use App\Http\Controllers\Settings\SignInHistoryController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/profile/email/{token}', [EmailChangeController::class, 'confirm'])->name('profile.email.confirm');
    Route::delete('settings/profile/email', [EmailChangeController::class, 'cancel'])->name('profile.email.cancel');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');
    Route::post('settings/recovery-codes', [RecoveryCodeController::class, 'regenerate'])->name('recovery-codes.regenerate');
    Route::delete('settings/recovery-codes', [RecoveryCodeController::class, 'acknowledge'])->name('recovery-codes.acknowledge');
    Route::delete('settings/connections/{application}', [ConnectionController::class, 'destroy'])->name('connections.destroy');

    Route::get('settings/sessions', [SessionController::class, 'index'])->name('sessions.edit');
    Route::delete('settings/sessions/others', [SessionController::class, 'destroyOthers'])->name('sessions.destroyOthers');
    Route::delete('settings/sessions/{id}', [SessionController::class, 'destroy'])->name('sessions.destroy');

    Route::get('settings/sign-in-history', [SignInHistoryController::class, 'index'])->name('sign-in-history.edit');

    Route::get('settings/appearance', fn () => Inertia::render('settings/Appearance'))->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
