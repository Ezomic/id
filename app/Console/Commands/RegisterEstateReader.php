<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Estate\CreateEstateReader;
use Illuminate\Console\Command;

class RegisterEstateReader extends Command
{
    protected $signature = 'id:estate-reader {name : Who reads the estate, e.g. Atlas}';

    protected $description = 'Register a client-credentials client that can read GET /api/admin/estate and nothing else';

    public function handle(CreateEstateReader $createEstateReader): int
    {
        $client = $createEstateReader->handle((string) $this->argument('name'));
        $id = $client->getKey();

        $this->info("Registered estate reader [{$client->name}] with the estate:read scope.");
        $this->newLine();
        $this->line('  client id:     '.(is_scalar($id) ? (string) $id : ''));
        $this->line('  client secret: '.$client->plainSecret);
        $this->newLine();
        $this->warn('The secret is shown once. Request tokens with grant_type=client_credentials and scope=estate:read.');

        return self::SUCCESS;
    }
}
