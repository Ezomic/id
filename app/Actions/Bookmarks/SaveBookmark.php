<?php

declare(strict_types=1);

namespace App\Actions\Bookmarks;

use App\Models\Bookmark;
use App\Models\User;
use App\Services\LinkMetadata;

class SaveBookmark
{
    public function __construct(private readonly LinkMetadata $metadata) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data): Bookmark
    {
        $url = $this->normalize($data['url']);
        $domain = $this->domain($url);
        $meta = $this->metadata->fetch($url);

        return $user->bookmarks()->create([
            'url' => $url,
            'title' => $meta['title'] ?? $domain,
            'image' => $meta['image'],
            'favicon' => $meta['favicon'],
            'domain' => $domain,
            'note' => $data['note'] ?? null,
            'tags' => $data['tags'] ?? [],
        ]);
    }

    private function normalize(string $url): string
    {
        $url = trim($url);

        return preg_match('#^https?://#i', $url) === 1 ? $url : 'https://'.$url;
    }

    private function domain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./i', '', $host) : null;
    }
}
