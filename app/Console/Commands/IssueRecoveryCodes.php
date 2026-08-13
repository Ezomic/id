<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Auth\GenerateRecoveryCodes;
use App\Models\User;
use Illuminate\Console\Command;

class IssueRecoveryCodes extends Command
{
    protected $signature = 'id:recovery-codes {email}';

    protected $description = 'Issue a fresh set of recovery codes for an account';

    /**
     * The escape hatch for an account with no usable factor.
     *
     * ID-68 gates the sensitive actions behind a passkey or a recovery code,
     * and regenerating recovery codes is one of those actions. An account with
     * neither therefore cannot re-authenticate and cannot mint a factor to
     * re-authenticate with, which is a deadlock the UI cannot break out of.
     *
     * Deliberately not gated: shell access to the droplet is already the
     * highest privilege in the estate, the same reasoning as id:admin.
     */
    public function handle(GenerateRecoveryCodes $generate): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No account with email ['.$this->argument('email').'].');

            return self::FAILURE;
        }

        $codes = $generate->handle($user);

        $this->info('Issued '.count($codes)." recovery codes for [{$user->email}].");
        $this->warn('Every previous code is now invalid.');
        $this->newLine();

        foreach ($codes as $code) {
            $this->line('  '.$code);
        }

        $this->newLine();
        $this->line('These are shown once. Save them before closing this session.');

        return self::SUCCESS;
    }
}
