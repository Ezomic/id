<?php

namespace Database\Seeders;

use App\Actions\Admin\CreateApplication;
use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Seeder;

class ApplicationsSeeder extends Seeder
{
    /**
     * @var list<array{name: string, slug: string, description: string, initials: string, accent: string, host: string, access: bool}>
     */
    private array $apps = [
        ['name' => 'Zero', 'slug' => 'zero', 'description' => 'Unified mail for every account', 'initials' => 'Z', 'accent' => '#3B82F6', 'host' => 'zero.thijssensoftware.nl', 'access' => true],
        ['name' => 'Tracker', 'slug' => 'tracker', 'description' => 'Issues, projects & releases', 'initials' => 'T', 'accent' => '#7C6BF0', 'host' => 'tracker.thijssensoftware.nl', 'access' => true],
        ['name' => 'CMS', 'slug' => 'cms', 'description' => 'Portfolio site & admin', 'initials' => 'C', 'accent' => '#10B981', 'host' => 'cms.thijssensoftware.nl', 'access' => true],
        ['name' => 'Billr', 'slug' => 'billr', 'description' => 'Invoicing & billing', 'initials' => 'B', 'accent' => '#E0A83E', 'host' => 'billr.thijssensoftware.nl', 'access' => true],
        ['name' => 'Finance', 'slug' => 'finance', 'description' => 'Household finance tracker', 'initials' => 'F', 'accent' => '#14B8A6', 'host' => 'finance.thijssensoftware.nl', 'access' => true],
        ['name' => 'Stocks', 'slug' => 'stocks', 'description' => 'Portfolio & IBKR rules', 'initials' => 'S', 'accent' => '#E5484D', 'host' => 'stocks.thijssensoftware.nl', 'access' => true],
        ['name' => 'Chronos', 'slug' => 'chronos', 'description' => 'Calendar across the suite', 'initials' => 'K', 'accent' => '#6366F1', 'host' => 'chronos.thijssensoftware.nl', 'access' => true],
        ['name' => 'Shop', 'slug' => 'shop', 'description' => 'Digital script sales', 'initials' => 'H', 'accent' => '#22C55E', 'host' => 'shop.thijssensoftware.nl', 'access' => false],
        ['name' => 'Hablas', 'slug' => 'hablas', 'description' => 'Language learning (CEFR)', 'initials' => 'L', 'accent' => '#EC4899', 'host' => 'hablas.thijssensoftware.nl', 'access' => false],
        ['name' => 'Groceries', 'slug' => 'groceries', 'description' => 'Pantry, scanner & lists', 'initials' => 'G', 'accent' => '#F97316', 'host' => 'groceries.thijssensoftware.nl', 'access' => false],
    ];

    public function run(CreateApplication $createApplication): void
    {
        $grantTo = User::whereIn('email', ['test@example.com', 'robbin_thijssen@hotmail.nl'])->pluck('id')->all();

        foreach ($this->apps as $app) {
            if (Application::where('slug', $app['slug'])->exists()) {
                continue;
            }

            $createApplication->handle([
                'name' => $app['name'],
                'slug' => $app['slug'],
                'description' => $app['description'],
                'initials' => $app['initials'],
                'accent' => $app['accent'],
                'launch_url' => "https://{$app['host']}",
                'redirect_uri' => "https://{$app['host']}/auth/callback",
                'users' => $app['access'] ? $grantTo : [],
            ]);
        }
    }
}
