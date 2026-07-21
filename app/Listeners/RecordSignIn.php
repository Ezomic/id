<?php

namespace App\Listeners;

use App\Models\Application;
use App\Models\SignInEvent;
use App\Notifications\NewDeviceSignIn;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

class RecordSignIn
{
    public function __construct(private readonly Request $request) {}

    public function handle(Login $event): void
    {
        $userId = $event->user->getAuthIdentifier();
        $userAgent = $this->request->userAgent();
        $fingerprint = hash('sha256', (string) $userAgent);

        $method = match ($this->request->route()?->getName()) {
            'passkey.login' => 'passkey',
            'login.code.verify' => 'email_code',
            default => 'other',
        };

        $seenBefore = SignInEvent::query()
            ->where('user_id', $userId)
            ->where('device_fingerprint', $fingerprint)
            ->exists();

        SignInEvent::create([
            'user_id' => $userId,
            'method' => $method,
            'ip_address' => $this->request->ip(),
            'user_agent' => $userAgent,
            'application' => $this->initiatingApplication(),
            'device_fingerprint' => $fingerprint,
        ]);

        if (! $seenBefore) {
            $event->user->notify(new NewDeviceSignIn($method, $this->request->ip(), $userAgent));
        }
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

        return Application::query()->where('oauth_client_id', $clientId)->value('name');
    }
}
