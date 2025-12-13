<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Database;

use Toporia\Framework\Console\Command;

/**
 * Class DbTableCommand
 *
 * Display information about a specific database table.
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
final class DbTableCommand extends Command
{
    protected string $signature = 'db:table {table : The name of the table}';

    protected string $description = 'Display information about the given database table';

    public function handle(): int
    {
        $table = $this->argument('table');

        $this->info("Table: {$table}");
        $this->line();
        $this->info('This command requires database to be configured.');

        return 0;
    }
}
