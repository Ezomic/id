<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\SignInEvent;
use App\Models\User;
use App\Notifications\SuspiciousSignInAttempts;
use App\Services\DeviceFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class RecordFailedSignIn
{
    /**
     * A burst is what is worth telling someone about. One typo is not, and an
     * email per attempt would itself be the attack.
     */
    public const BURST_THRESHOLD = 5;

    public const BURST_WINDOW_MINUTES = 15;

    public function __construct(
        private readonly Request $request,
        private readonly DeviceFingerprint $fingerprints,
    ) {}

    /**
     * $user is null when the attempt was against an address with no account.
     * Nothing identifying the attempted address is stored in that case, so the
     * log cannot be read back as a list of who does or does not have an account.
     */
    public function handle(?User $user, string $method): void
    {
        $userAgent = $this->request->userAgent();
        $ip = $this->request->ip();

        SignInEvent::create([
            'user_id' => $user?->id,
            'method' => $method,
            'outcome' => SignInEvent::FAILURE,
            'ip_address' => $ip,
            'network' => $this->fingerprints->networkFor($ip),
            'user_agent' => $userAgent,
            'device_fingerprint' => $this->fingerprints->forUserAgent($userAgent),
        ]);

        if ($user !== null && $this->justCrossedThreshold($user)) {
            $user->notify(new SuspiciousSignInAttempts(
                attempts: self::BURST_THRESHOLD,
                windowMinutes: self::BURST_WINDOW_MINUTES,
                ip: $ip,
            ));
        }
    }

    /**
     * Firing only on the attempt that reaches the threshold gives one email per
     * burst rather than one per attempt from then on.
     */
    private function justCrossedThreshold(User $user): bool
    {
        $recent = SignInEvent::query()
            ->where('user_id', $user->id)
            ->where('outcome', SignInEvent::FAILURE)
            ->where('created_at', '>=', CarbonImmutable::now()->subMinutes(self::BURST_WINDOW_MINUTES))
            ->count();

        return $recent === self::BURST_THRESHOLD;
    }
}
