<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class LinkMetadata
{
    private const MAX_REDIRECTS = 3;

    private const MAX_BYTES = 1024 * 1024;

    public function __construct(private readonly PublicAddressResolver $resolver) {}

    /**
     * Read the title and preview image off a page. Never throws: a link that
     * cannot be reached is still worth saving, just without its metadata.
     *
     * @return array{title: string|null, image: string|null, favicon: string|null}
     */
    public function fetch(string $url): array
    {
        try {
            $response = $this->get($url);

            if ($response === null || ! $response->successful()) {
                return ['title' => null, 'image' => null, 'favicon' => null];
            }

            $html = $response->body();

            return [
                'title' => $this->title($html),
                'image' => $this->absolute($this->image($html), $url),
                'favicon' => $this->favicon($html, $url),
            ];
        } catch (Throwable) {
            return ['title' => null, 'image' => null, 'favicon' => null];
        }
    }

    /**
     * The URL is whatever a signed-in user typed, and the title comes back to
     * them, so this must never reach the box's own loopback services or the
     * private network. Each hop is resolved, checked and pinned to the checked
     * address (so a second DNS answer cannot swap in another), and redirects
     * are followed by hand so every hop gets the same check.
     */
    private function get(string $url): ?Response
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->target($url);

            if ($target === null) {
                return null;
            }

            $response = Http::timeout(5)
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'ThijssensoftwareID/1.0 (+bookmarks)'])
                ->withOptions($this->transportOptions(...$target))
                ->get($url);

            $location = $response->header('Location');

            if (! $response->redirect() || $location === '') {
                return $response;
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }

        return null;
    }

    /**
     * @return array{0: string, 1: int, 2: string}|null host, port and the address to connect to
     */
    private function target(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || ! isset($parts['host'])) {
            return null;
        }

        $address = $this->resolver->resolve($parts['host']);

        if ($address === null) {
            return null;
        }

        return [$parts['host'], $parts['port'] ?? ($scheme === 'https' ? 443 : 80), $address];
    }

    /**
     * @return array<string, mixed>
     */
    private function transportOptions(string $host, int $port, string $address): array
    {
        $address = str_contains($address, ':') ? "[{$address}]" : $address;

        return [
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"]],
            'protocols' => ['http', 'https'],
            // Aborts mid-transfer, so a huge or endless body never sits in memory.
            'progress' => fn (int $expected, int $downloaded): bool => max($expected, $downloaded) > self::MAX_BYTES,
        ];
    }

    /**
     * Resolve a favicon from the page's <link rel="...icon...">, falling back
     * to the well-known /favicon.ico on the same origin (the page responded,
     * so the origin is reachable). Returns null only when the origin can't be
     * parsed.
     */
    private function favicon(string $html, string $base): ?string
    {
        $patterns = [
            '/<link[^>]+rel=["\'][^"\']*icon[^"\']*["\'][^>]+href=["\']([^"\']+)["\']/i',
            '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*icon[^"\']*["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                $resolved = $this->absolute($this->clean($matches[1]), $base);

                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        $parts = parse_url($base);

        if (isset($parts['scheme'], $parts['host'])) {
            return $parts['scheme'].'://'.$parts['host'].'/favicon.ico';
        }

        return null;
    }

    private function title(string $html): ?string
    {
        $og = $this->meta($html, 'og:title');

        if ($og !== null) {
            return $og;
        }

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) === 1) {
            return $this->clean($matches[1]);
        }

        return null;
    }

    private function image(string $html): ?string
    {
        return $this->meta($html, 'og:image') ?? $this->meta($html, 'twitter:image');
    }

    private function meta(string $html, string $property): ?string
    {
        $quoted = preg_quote($property, '/');

        // property="og:image" content="..." and the reversed attribute order.
        $patterns = [
            '/<meta[^>]+(?:property|name)=["\']'.$quoted.'["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']'.$quoted.'["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                return $this->clean($matches[1]);
            }
        }

        return null;
    }

    private function absolute(?string $candidate, string $base): ?string
    {
        if ($candidate === null || str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
            return $candidate;
        }

        $parts = parse_url($base);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        return $origin.'/'.ltrim($candidate, '/');
    }

    private function clean(string $value): ?string
    {
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
