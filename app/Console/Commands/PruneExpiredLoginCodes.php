<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PruneExpiredLoginCodes extends Command
{
    protected $signature = 'id:prune-login-codes';

    protected $description = 'Clear login codes that expired without being used';

    /**
     * VerifyLoginCode only clears a code on a successful sign-in or once the
     * attempt ceiling is hit, so a code that is simply never used sits hashed
     * on the users row indefinitely.
     */
    public function handle(): int
    {
        $cleared = User::query()
            ->whereNotNull('login_code_expires_at')
            ->where('login_code_expires_at', '<', CarbonImmutable::now())
            ->update([
                'login_code_hash' => null,
                'login_code_expires_at' => null,
                'login_code_attempts' => 0,
            ]);

        $this->info("Cleared {$cleared} expired login code(s).");

        return self::SUCCESS;
    }
}
