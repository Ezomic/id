<?php

namespace App\Console\Commands;

use App\Actions\Admin\CreateApplication;
use App\Models\Application;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RegisterApplication extends Command
{
    protected $signature = 'id:app {name} {slug} {redirect : The OAuth callback URL}';

    protected $description = 'Register a workflow app as an OAuth client (authorization_code + PKCE)';

    public function handle(CreateApplication $createApplication): int
    {
        $slug = Str::slug($this->argument('slug'));

        if (Application::where('slug', $slug)->exists()) {
            $this->error("An application with slug [{$slug}] already exists.");

            return self::FAILURE;
        }

        $result = $createApplication->handle([
            'name' => $this->argument('name'),
            'slug' => $slug,
            'redirect_uri' => $this->argument('redirect'),
        ]);

        $this->info("Registered application [{$slug}].");
        $this->newLine();
        $this->line('Add these to the client app .env:');
        $this->line('  THIJSSENSOFTWARE_ID_CLIENT_ID='.$result['client_id']);
        $this->line('  THIJSSENSOFTWARE_ID_CLIENT_SECRET='.$result['client_secret']);

        return self::SUCCESS;
    }
}
