<?php

namespace Database\Factories;

use App\Models\Bookmark;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bookmark>
 */
class BookmarkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = fake()->domainName();

        return [
            'user_id' => User::factory(),
            'url' => 'https://'.$domain.'/'.fake()->slug(),
            'title' => fake()->sentence(4),
            'domain' => $domain,
            'image' => null,
            'note' => null,
            'tags' => [],
            'read_at' => null,
            'archived_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
