<?php

namespace App\Console\Commands;

use App\Models\Application;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

class RegisterApplication extends Command
{
    protected $signature = 'id:app {name} {slug} {redirect : The OAuth callback URL}';

    protected $description = 'Register a workflow app as an OAuth client (authorization_code + PKCE)';

    public function handle(ClientRepository $clients): int
    {
        $slug = Str::slug($this->argument('slug'));

        if (Application::where('slug', $slug)->exists()) {
            $this->error("An application with slug [{$slug}] already exists.");

            return self::FAILURE;
        }

        $client = $clients->createAuthorizationCodeGrantClient(
            name: $this->argument('name'),
            redirectUris: [$this->argument('redirect')],
            confidential: true,
        );

        Application::create([
            'name' => $this->argument('name'),
            'slug' => $slug,
            'oauth_client_id' => $client->getKey(),
            'active' => true,
        ]);

        $this->info("Registered application [{$slug}].");
        $this->newLine();
        $this->line('Add these to the client app .env:');
        $this->line('  THIJSSENSOFTWARE_ID_CLIENT_ID='.$client->getKey());
        $this->line('  THIJSSENSOFTWARE_ID_CLIENT_SECRET='.$client->plainSecret);

        return self::SUCCESS;
    }
}
