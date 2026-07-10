<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\User;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'id:admin {email} {name} {--all-apps : Grant access to every registered application}';

    protected $description = 'Create or promote an administrator (passwordless)';

    public function handle(): int
    {
        $user = User::updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => $this->argument('name'), 'is_admin' => true],
        );

        if ($this->option('all-apps')) {
            $user->applications()->sync(Application::pluck('id'));
        }

        $this->info("Admin [{$user->email}] is ready. Sign in with an email code or passkey.");

        return self::SUCCESS;
    }
}
