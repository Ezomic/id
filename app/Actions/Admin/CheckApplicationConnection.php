<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Application;
use App\Models\LogoutNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything needed to tell whether a consumer is wired up correctly is already
 * known here: the redirect URI, the client state, the derived logout endpoint
 * and the recent delivery history. Before this, the only way to find out was to
 * sign in by hand, which is how the ID-65 rollout was verified.
 */
final class CheckApplicationConnection
{
    /**
     * @return array{healthy: bool, checks: list<array{name: string, ok: bool, detail: string}>}
     */
    public function handle(Application $application): array
    {
        $checks = [
            $this->client($application),
            $this->redirectUri($application),
            $this->logoutSecret($application),
            $this->logoutEndpoint($application),
            $this->typedEvents($application),
            $this->deliveries($application),
        ];

        return [
            'healthy' => ! in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function client(Application $application): array
    {
        $client = $application->oauthClient;

        return match (true) {
            $client === null => $this->fail('OAuth client', 'No backing client. Re-register with id:app.'),
            (bool) $client->revoked => $this->fail('OAuth client', 'Revoked. The application is inactive, so authorize and token requests are refused.'),
            default => $this->pass('OAuth client', 'Active.'),
        };
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function redirectUri(Application $application): array
    {
        $uri = $application->redirectUri();

        if ($uri === null) {
            return $this->fail('Redirect URI', 'None registered.');
        }

        if (! str_ends_with($uri, '/auth/sso/callback')) {
            return $this->fail('Redirect URI', "{$uri} does not follow the id-client convention, so the logout endpoint cannot be derived.");
        }

        return $this->pass('Redirect URI', $uri);
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function logoutSecret(Application $application): array
    {
        return $application->logout_secret === null
            ? $this->fail('Logout secret', 'Missing. Issue one with id:app --rotate.')
            : $this->pass('Logout secret', 'Set.');
    }

    /**
     * Probes with a signed call whose subject is nobody. A consumer that
     * verifies the signature will accept it and find no user to sign out, so
     * running this can never end anyone's session.
     *
     * @return array{name: string, ok: bool, detail: string}
     */
    private function logoutEndpoint(Application $application): array
    {
        $endpoint = $application->logoutUrl();
        $secret = $application->logout_secret;

        if ($endpoint === null || $secret === null) {
            return $this->fail('Logout endpoint', 'Cannot be derived without a conventional redirect URI and a secret.');
        }

        try {
            $response = $this->probe($endpoint, $secret);
        } catch (Throwable $e) {
            // A consumer being down is a result, not an exception.
            return $this->fail('Logout endpoint', 'Unreachable: '.Str::limit($e->getMessage(), 120));
        }

        return match (true) {
            $response->status() === 404 => $this->fail('Logout endpoint', 'Returns 404. Redeploy with id-client 0.2 or later.'),
            $response->status() === 501 => $this->fail('Logout endpoint', 'Returns 501. Redeploy with THIJSSENSOFTWARE_ID_LOGOUT_SECRET set.'),
            $response->status() === 401 => $this->fail('Logout endpoint', 'Rejected the signature. The consumer has a different logout secret; rotate and redeploy.'),
            $response->successful() => $this->pass('Logout endpoint', 'Accepted a signed probe.'),
            default => $this->fail('Logout endpoint', 'Returned HTTP '.$response->status().'.'),
        };
    }

    /**
     * Which back-channel dialect the consumer speaks, asked rather than
     * configured because a hand-maintained version column goes stale the first
     * time an app is redeployed without anyone updating it here.
     *
     * The probe carries an event no client has a handler for. 0.3 and later
     * answer `ignored`; 0.2 answers `ok`, because it does not look at the event
     * at all and has just tried to sign out a subject that does not exist.
     * Distinguishing them is what makes it safe to send `user.updated`.
     *
     * @return array{name: string, ok: bool, detail: string}
     */
    private function typedEvents(Application $application): array
    {
        $endpoint = $application->logoutUrl();
        $secret = $application->logout_secret;

        if ($endpoint === null || $secret === null) {
            return $this->fail('Event handling', 'Cannot be probed without a conventional redirect URI and a secret.');
        }

        try {
            $response = $this->probe($endpoint, $secret, ['event' => 'connection.probe']);
        } catch (Throwable $e) {
            return $this->fail('Event handling', 'Unreachable: '.Str::limit($e->getMessage(), 120));
        }

        $understands = $response->successful() && $response->json('status') === 'ignored';

        $application->forceFill([
            'typed_events_confirmed_at' => $understands ? CarbonImmutable::now() : null,
        ])->save();

        if ($understands) {
            return $this->pass('Event handling', 'Reads the event field, so profile updates are delivered.');
        }

        return $this->fail(
            'Event handling',
            'Ends the session on any event it accepts, so profile updates are withheld. Redeploy with id-client 0.3 or later.',
        );
    }

    /**
     * A signed call whose subject is nobody. A consumer that verifies the
     * signature accepts it and finds no user to act on, so probing can never
     * end a real session.
     *
     * @param  array<string, mixed>  $extra
     *
     * @throws Throwable
     */
    private function probe(string $endpoint, string $secret, array $extra = []): Response
    {
        $payload = json_encode([
            'sub' => 'connection-probe-'.Str::random(32),
            'issued_at' => CarbonImmutable::now()->getTimestamp(),
            'nonce' => Str::random(32),
            'probe' => true,
            ...$extra,
        ], JSON_THROW_ON_ERROR);

        return Http::timeout(5)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Id-Signature' => hash_hmac('sha256', $payload, $secret),
            ])
            ->withBody($payload, 'application/json')
            ->post($endpoint);
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function deliveries(Application $application): array
    {
        $stuck = LogoutNotification::query()
            ->where('application_id', $application->id)
            ->whereNull('delivered_at')
            ->count();

        return $stuck === 0
            ? $this->pass('Logout deliveries', 'Nothing outstanding.')
            : $this->fail('Logout deliveries', "{$stuck} sign-out(s) not accepted yet. See the logout deliveries screen.");
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function pass(string $name, string $detail): array
    {
        return ['name' => $name, 'ok' => true, 'detail' => $detail];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function fail(string $name, string $detail): array
    {
        return ['name' => $name, 'ok' => false, 'detail' => $detail];
    }
}
