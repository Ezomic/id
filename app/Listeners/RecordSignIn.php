<?php

namespace App\Listeners;

use App\Models\Application;
use App\Models\SignInEvent;
use App\Notifications\NewDeviceSignIn;
use App\Services\DeviceFingerprint;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

class RecordSignIn
{
    public function __construct(
        private readonly Request $request,
        private readonly DeviceFingerprint $fingerprints,
    ) {}

    public function handle(Login $event): void
    {
        $userId = $event->user->getAuthIdentifier();
        $userAgent = $this->request->userAgent();
        $ip = $this->request->ip();

        $fingerprint = $this->fingerprints->forUserAgent($userAgent);
        $network = $this->fingerprints->networkFor($ip);

        $history = SignInEvent::query()->where('user_id', $userId);

        $isFirstEver = ! (clone $history)->exists();
        $knownDevice = (clone $history)->where('device_fingerprint', $fingerprint)->exists();
        $knownNetwork = $network !== null && (clone $history)->where('network', $network)->exists();

        SignInEvent::create([
            'user_id' => $userId,
            'method' => $this->method(),
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'application' => $this->initiatingApplication(),
            'device_fingerprint' => $fingerprint,
            'network' => $network,
        ]);

        // Every device is new on the first sign-in, so alerting there is noise.
        if ($isFirstEver || ($knownDevice && $knownNetwork)) {
            return;
        }

        $event->user->notify(new NewDeviceSignIn(
            method: $this->method(),
            ip: $ip,
            device: $this->fingerprints->label($userAgent),
            newDevice: ! $knownDevice,
            newNetwork: ! $knownNetwork,
        ));
    }

    private function method(): string
    {
        return match ($this->request->route()?->getName()) {
            'passkey.login' => 'passkey',
            'login.code.verify' => 'email_code',
            default => 'other',
        };
    }

    /**
     * The OAuth client that kicked off the login, if this was an authorize flow.
     */
    private function initiatingApplication(): ?string
    {
        $intended = $this->request->session()->get('url.intended');

        if (! is_string($intended)) {
            return null;
        }

        parse_str((string) parse_url($intended, PHP_URL_QUERY), $query);
        $clientId = $query['client_id'] ?? null;

        if (! is_string($clientId) || $clientId === '') {
            return null;
        }

        $name = Application::query()->where('oauth_client_id', $clientId)->value('name');

        return is_string($name) ? $name : null;
    }
}
