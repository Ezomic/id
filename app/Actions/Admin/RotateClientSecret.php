<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Access\RevokeClientTokens;
use App\Models\AccessAudit;
use App\Models\Application;
use Laravel\Passport\ClientRepository;

final class RotateClientSecret
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly RevokeClientTokens $revokeClientTokens,
    ) {}

    /**
     * A secret that has leaked out of one of the seven client repositories used
     * to need database surgery to replace. Returns the new plaintext, which is
     * the only time it exists in a readable form.
     *
     * Returns null when the application has no backing client, so the caller can
     * refuse cleanly rather than rotate half of something.
     */
    public function handle(Application $application): ?string
    {
        $client = $application->oauthClient;

        if ($client === null) {
            return null;
        }

        $this->clients->regenerateSecret($client);

        // Tokens obtained with the old secret would otherwise outlive it by up
        // to 30 days, which would make the rotation pointless.
        $this->revokeClientTokens->handle($client);

        AccessAudit::log('client_secret_rotate', ['application_id' => $application->id]);

        return $client->plainSecret;
    }
}
