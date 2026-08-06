<?php

namespace App\Console\Commands;

use App\Actions\Admin\CreateApplication;
use App\Actions\Admin\RotateClientSecret;
use App\Models\Application;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RegisterApplication extends Command
{
    protected $signature = 'id:app
        {name? : The display name}
        {slug? : The application slug}
        {redirect? : The OAuth callback URL}
        {--rotate= : Issue a new secret for an existing application slug}';

    protected $description = 'Register a workflow app as an OAuth client (authorization_code + PKCE), or rotate its secret';

    public function handle(CreateApplication $createApplication, RotateClientSecret $rotateSecret): int
    {
        $rotate = $this->option('rotate');

        if (is_string($rotate) && $rotate !== '') {
            return $this->rotate($rotate, $rotateSecret);
        }

        return $this->register($createApplication);
    }

    private function rotate(string $slug, RotateClientSecret $rotateSecret): int
    {
        $application = Application::where('slug', Str::slug($slug))->first();

        if ($application === null) {
            $this->error("No application with slug [{$slug}].");

            return self::FAILURE;
        }

        $secret = $rotateSecret->handle($application);

        if ($secret === null) {
            $this->error("Application [{$application->slug}] has no OAuth client to rotate.");

            return self::FAILURE;
        }

        $this->info("Rotated the secret for [{$application->slug}].");
        $this->warn('Every token issued under the old secret has been revoked. The app is signed out until it is redeployed.');
        $this->newLine();
        $this->line('Update the client app .env:');
        $this->line('  THIJSSENSOFTWARE_ID_CLIENT_ID='.$application->oauth_client_id);
        $this->line('  THIJSSENSOFTWARE_ID_CLIENT_SECRET='.$secret);

        return self::SUCCESS;
    }

    private function register(CreateApplication $createApplication): int
    {
        foreach (['name', 'slug', 'redirect'] as $argument) {
            if (! is_string($this->argument($argument)) || $this->argument($argument) === '') {
                $this->error('name, slug and redirect are all required when registering an application.');

                return self::FAILURE;
            }
        }

        $slug = Str::slug((string) $this->argument('slug'));

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
