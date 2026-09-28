<?php

declare(strict_types=1);

namespace App\Actions\Estate;

use App\Models\OAuthClient;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

class CreateEstateReader
{
    public function __construct(private readonly ClientRepository $clients) {}

    /**
     * A client-credentials client that can be issued estate:read and nothing
     * else: no redirect URIs, no user flows, and no application record, so it
     * never shows up in the portal or holds anyone's access.
     */
    public function handle(string $name): Client
    {
        $client = $this->clients->createClientCredentialsGrantClient($name);

        $client->forceFill(['scopes' => [OAuthClient::ESTATE_READ]])->save();

        return $client;
    }
}
