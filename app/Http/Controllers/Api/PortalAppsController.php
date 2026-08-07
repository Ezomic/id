<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Portal\LaunchableAppsForUser;
use App\Http\Controllers\Controller;
use App\Models\PortalLookup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Client;
use Laravel\Passport\Guards\TokenGuard;
use Symfony\Component\HttpFoundation\Response;

class PortalAppsController extends Controller
{
    /**
     * Machine-to-machine: given a user's email, return the apps that user can
     * launch. Called by the app switcher's own client (client-credentials) to
     * render its in-app portal.
     */
    public function __invoke(Request $request, LaunchableAppsForUser $launchableApps): JsonResponse
    {
        $client = $this->authorizeCaller();

        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = $request->string('email')->toString();
        $user = User::where('email', $email)->first();

        $this->record($client, $email, $user !== null, $request->ip());

        if ($user === null) {
            return response()->json(['applications' => [], 'categories' => []]);
        }

        return response()->json($launchableApps->handle($user));
    }

    /**
     * Every OAuth client registered on this ID is a trusted first-party app, so
     * any valid client-credentials token may query the switcher on a user's
     * behalf (ID-33). The `client` middleware already validated the token.
     */
    private function authorizeCaller(): Client
    {
        $guard = Auth::guard('api');
        $client = $guard instanceof TokenGuard ? $guard->client() : null;

        abort_if(! $client instanceof Client, Response::HTTP_FORBIDDEN);

        return $client;
    }

    private function clientId(Client $client): string
    {
        $key = $client->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * The response tells a caller whether an address has an account, so without
     * a record a leaked client secret is an unmetered directory of the estate
     * with nothing to reconstruct afterwards. Rotating the secret is the fix;
     * this is what says how much was taken first.
     */
    private function record(Client $client, string $email, bool $matched, ?string $ip): void
    {
        PortalLookup::create([
            'oauth_client_id' => $this->clientId($client),
            'subject_email' => $email,
            'matched' => $matched,
            'ip_address' => $ip,
        ]);
    }
}
