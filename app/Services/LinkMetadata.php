<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class LinkMetadata
{
    /**
     * Read the title and preview image off a page. Never throws: a link that
     * cannot be reached is still worth saving, just without its metadata.
     *
     * @return array{title: string|null, image: string|null, favicon: string|null}
     */
    public function fetch(string $url): array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => 'ThijssensoftwareID/1.0 (+bookmarks)'])
                ->get($url);

            if (! $response->successful()) {
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
