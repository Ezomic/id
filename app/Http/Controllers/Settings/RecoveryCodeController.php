<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Auth\GenerateRecoveryCodes;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecoveryCodeController extends Controller
{
    use InteractsWithCurrentUser;

    public function regenerate(Request $request, GenerateRecoveryCodes $generate): RedirectResponse
    {
        $codes = $generate->handle($this->currentUser($request));

        // The plaintext exists nowhere else, so it rides the session to the one
        // page that displays it and is dropped the moment it has been shown.
        $request->session()->put('recovery_codes', $codes);

        return to_route('security.edit');
    }

    public function acknowledge(Request $request): RedirectResponse
    {
        $request->session()->forget('recovery_codes');

        return to_route('security.edit');
    }
}
