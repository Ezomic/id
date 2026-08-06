<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\LogoutNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class DeliverLogoutNotification
{
    public function handle(LogoutNotification $notification): bool
    {
        $application = $notification->application;
        $secret = $application?->logout_secret;

        if ($secret === null) {
            $this->fail($notification, 'Application has no logout secret.');

            return false;
        }

        $payload = json_encode([
            'sub' => (string) $notification->user_id,
            'issued_at' => CarbonImmutable::now()->getTimestamp(),
            'nonce' => Str::random(32),
        ], JSON_THROW_ON_ERROR);

        try {
            // Short timeout on purpose: a consumer being slow must not hold up
            // the other six, and an undelivered row is retried on the schedule.
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Id-Signature' => hash_hmac('sha256', $payload, $secret),
                ])
                ->withBody($payload, 'application/json')
                ->post($notification->endpoint);
        } catch (Throwable $e) {
            $this->fail($notification, $e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            $this->fail($notification, 'HTTP '.$response->status());

            return false;
        }

        $notification->forceFill([
            'attempts' => $notification->attempts + 1,
            'delivered_at' => CarbonImmutable::now(),
            'last_error' => null,
        ])->save();

        return true;
    }

    private function fail(LogoutNotification $notification, string $error): void
    {
        $notification->forceFill([
            'attempts' => $notification->attempts + 1,
            'last_error' => Str::limit($error, 500),
        ])->save();
    }
}
