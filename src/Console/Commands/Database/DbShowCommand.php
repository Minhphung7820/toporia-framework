<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Database;

use Toporia\Framework\Console\Command;

/**
 * Class DbShowCommand
 *
 * Display information about the given database including driver, host, and connection details.
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
final class DbShowCommand extends Command
{
    protected string $signature = 'db:show {--counts : Show the table row counts}';

    protected string $description = 'Display information about the given database';

    public function handle(): int
    {
        $driver = config('database.default', 'mysql');

        $this->info('Database Information');
        $this->line();

        $config = config("database.connections.{$driver}", []);

        $rows = [
            ['Driver', $driver],
            ['Host', $config['host'] ?? 'N/A'],
            ['Port', $config['port'] ?? 'N/A'],
            ['Database', $config['database'] ?? 'N/A'],
            ['Username', $config['username'] ?? 'N/A'],
        ];

        $this->table(['Property', 'Value'], $rows);

        $this->newLine();
        $this->info('This command requires database to be configured to show tables.');

        return 0;
    }
}
