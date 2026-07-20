<?php

namespace App\Policies;

use App\Models\Bookmark;
use App\Models\User;

class BookmarkPolicy
{
    public function update(User $user, Bookmark $bookmark): bool
    {
        return $bookmark->user_id === $user->id;
    }

    public function delete(User $user, Bookmark $bookmark): bool
    {
        return $bookmark->user_id === $user->id;
    }
}
