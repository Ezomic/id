<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Hashing the raw user agent string made a browser auto-update look like a new
 * device and made an attacker on a stock Chrome string look like a familiar one.
 * Fingerprinting the browser family and platform, with versions stripped, gets
 * both cases the right way round.
 */
final class DeviceFingerprint
{
    /**
     * Order matters: Edge and Opera both carry "Chrome" in their user agent, and
     * every Chromium browser carries "Safari".
     *
     * @var array<string, string>
     */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    /**
     * @var array<string, string>
     */
    private const PLATFORMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'CrOS' => 'ChromeOS',
        'Mac OS X' => 'macOS',
        'Windows' => 'Windows',
        'Linux' => 'Linux',
    ];

    public function forUserAgent(?string $userAgent): string
    {
        return hash('sha256', $this->label($userAgent));
    }

    public function label(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'Unknown device';
        }

        $browser = $this->match(self::BROWSERS, $userAgent);
        $platform = $this->match(self::PLATFORMS, $userAgent);

        if ($browser === null && $platform === null) {
            // Nothing recognisable, so fall back to the raw string rather than
            // collapsing every exotic client into one shared fingerprint.
            return Str::limit($userAgent, 120, '');
        }

        return trim(($browser ?? 'Unknown browser').' on '.($platform ?? 'unknown platform'));
    }

    /**
     * IPs move around constantly on mobile networks, so the comparable unit is
     * the network the address sits in, not the address itself.
     */
    public function networkFor(?string $ip): ?string
    {
        if ($ip === null || trim($ip) === '') {
            return null;
        }

        if (str_contains($ip, ':')) {
            $hextets = array_slice(explode(':', $ip), 0, 3);

            return implode(':', $hextets).'::/48';
        }

        $octets = explode('.', $ip);

        if (count($octets) !== 4) {
            return $ip;
        }

        return implode('.', array_slice($octets, 0, 3)).'.0/24';
    }

    /**
     * @param  array<string, string>  $candidates
     */
    private function match(array $candidates, string $userAgent): ?string
    {
        foreach ($candidates as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
