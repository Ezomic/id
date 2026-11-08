<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SetQrLoginEnabled;
use App\Http\Controllers\Controller;
use App\Services\QrLoginPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(QrLoginPolicy $policy): Response
    {
        return Inertia::render('admin/Settings', ['qrLoginEnabled' => $policy->enabled()]);
    }

    public function update(Request $request, SetQrLoginEnabled $setQrLoginEnabled): RedirectResponse
    {
        $request->validate(['qr_login_enabled' => ['sometimes', 'boolean']]);

        $setQrLoginEnabled->handle($request->boolean('qr_login_enabled'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }
}
