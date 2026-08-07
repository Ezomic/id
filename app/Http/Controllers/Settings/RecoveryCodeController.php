<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Auth\GenerateRecoveryCodes;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
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

    /**
     * Recording this is what stops the prompt. Rendering the page is not enough:
     * a user who opens security settings and closes the tab has still not saved
     * anything, and the plaintext is gone the moment the session ends.
     */
    public function acknowledge(Request $request): RedirectResponse
    {
        $this->currentUser($request)->forceFill([
            'recovery_codes_acknowledged_at' => CarbonImmutable::now(),
        ])->save();

        $request->session()->forget('recovery_codes');

        return to_route('security.edit');
    }
}
