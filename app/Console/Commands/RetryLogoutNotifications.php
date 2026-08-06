<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Auth\DeliverLogoutNotification;
use App\Models\LogoutNotification;
use Illuminate\Console\Command;

class RetryLogoutNotifications extends Command
{
    protected $signature = 'id:retry-logout-notifications';

    protected $description = 'Retry back-channel logout calls that a consumer app did not accept';

    public function handle(DeliverLogoutNotification $deliver): int
    {
        $pending = LogoutNotification::query()
            ->whereNull('delivered_at')
            ->where('attempts', '<', LogoutNotification::MAX_ATTEMPTS)
            ->with('application')
            ->get();

        $delivered = 0;

        foreach ($pending as $notification) {
            if ($deliver->handle($notification)) {
                $delivered++;
            }
        }

        $this->info("Delivered {$delivered} of {$pending->count()} pending logout notification(s).");

        return self::SUCCESS;
    }
}
