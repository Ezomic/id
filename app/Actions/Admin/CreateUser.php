<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;

class CreateUser
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'is_admin' => $data['is_admin'] ?? false,
        ]);

        $user->applications()->sync($data['applications'] ?? []);

        return $user;
    }
}
