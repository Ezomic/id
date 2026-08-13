<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\CheckApplicationConnection;
use App\Models\Application;
use Illuminate\Console\Command;

class CheckApplications extends Command
{
    protected $signature = 'id:check {slug? : Limit to one application}';

    protected $description = 'Check that consumer apps are wired up for SSO and single logout';

    public function handle(CheckApplicationConnection $check): int
    {
        $applications = Application::query()
            ->when($this->argument('slug'), fn ($query, $slug) => $query->where('slug', $slug))
            ->orderBy('name')
            ->get();

        if ($applications->isEmpty()) {
            $this->error('No matching applications.');

            return self::FAILURE;
        }

        $unhealthy = 0;

        foreach ($applications as $application) {
            $result = $check->handle($application);
            $unhealthy += $result['healthy'] ? 0 : 1;

            $this->newLine();
            $this->line(($result['healthy'] ? '<info>OK</info>  ' : '<error>FAIL</error>').' '.$application->name);

            foreach ($result['checks'] as $line) {
                $this->line(sprintf('     %s %s: %s', $line['ok'] ? '✓' : '✗', $line['name'], $line['detail']));
            }
        }

        $this->newLine();
        $this->line("{$unhealthy} of {$applications->count()} application(s) need attention.");

        return $unhealthy === 0 ? self::SUCCESS : self::FAILURE;
    }
}
