<?php

declare(strict_types=1);

namespace App\Actions\Bookmarks;

use App\Models\Bookmark;

class UpdateBookmark
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Bookmark $bookmark, array $data): void
    {
        $attributes = [];

        foreach (['title', 'note', 'tags'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('read', $data)) {
            $attributes['read_at'] = $data['read'] ? now() : null;
        }

        if (array_key_exists('archived', $data)) {
            $attributes['archived_at'] = $data['archived'] ? now() : null;
        }

        $bookmark->forceFill($attributes)->save();
    }
}
