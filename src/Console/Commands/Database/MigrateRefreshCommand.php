<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Database;

use Toporia\Framework\Console\Command;

/**
 * Class MigrateRefreshCommand
 *
 * Reset and re-run all migrations by rolling back and migrating again.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\Database
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MigrateRefreshCommand extends Command
{
    protected string $signature = 'migrate:refresh {--seed : Indicates if the seed task should be run} {--step= : The number of migrations to be reverted & re-run} {--force : Force the operation to run in production}';

    protected string $description = 'Reset and re-run all migrations';

    public function handle(): int
    {
        if (!$this->confirmToProceed()) {
            return 1;
        }

        $step = $this->option('step');

        // Rollback
        if ($step) {
            $this->info("Rolling back {$step} migration(s)...");
        } else {
            $this->info('Rolling back all migrations...');
        }

        // Note: Would call migrate:rollback command here
        $this->info('(Rollback would happen here)');

        $this->newLine();

        // Run migrations
        $this->info('Running migrations...');
        // Note: Would call migrate command here
        $this->info('(Migration execution would happen here)');

        // Run seeders if requested
        if ($this->option('seed')) {
            $this->newLine();
            $this->info('Running seeders...');
            // Note: Would call db:seed command here
            $this->info('(Seeder execution would happen here)');
        }

        return 0;
    }

    private function confirmToProceed(): bool
    {
        $env = env('APP_ENV', 'production');

        if ($env === 'production' && !$this->option('force')) {
            $this->warn('Application is in production!');
            return $this->confirm('Do you really wish to run this command?', false);
        }

        return true;
    }
}
