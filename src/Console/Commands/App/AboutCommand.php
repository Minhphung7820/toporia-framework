<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\App;

use Toporia\Framework\Console\Command;

/**
 * Class AboutCommand
 *
 * Display basic information about your application including environment,
 * drivers configuration, and PHP extensions.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\App
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class AboutCommand extends Command
{
    protected string $signature = 'about';

    protected string $description = 'Display basic information about your application';

    public function handle(): int
    {
        $this->printEnvironment();
        $this->newLine();
        $this->printDrivers();
        $this->newLine();
        $this->printPhpInfo();

        return 0;
    }

    private function printEnvironment(): void
    {
        $this->info('Environment');
        $this->line();

        $rows = [
            ['Application Name', config('app.name', 'Toporia')],
            ['Version', config('app.version', '1.0.0')],
            ['PHP Version', PHP_VERSION],
            ['Environment', env('APP_ENV', 'production')],
            ['Debug Mode', env('APP_DEBUG', false) ? 'ENABLED' : 'DISABLED'],
            ['URL', config('app.url', 'http://localhost')],
            ['Timezone', config('app.timezone', 'UTC')],
            ['Locale', config('app.locale', 'en')],
        ];

        $this->table(['', ''], $rows);
    }

    private function printDrivers(): void
    {
        $this->info('Drivers');
        $this->line();

        $rows = [
            ['Cache', config('cache.default', 'file')],
            ['Database', config('database.default', 'mysql')],
            ['Queue', config('queue.default', 'sync')],
            ['Session', config('session.driver', 'file')],
            ['Logging', config('logging.default', 'stack')],
            ['Mail', config('mail.default', 'smtp')],
            ['Broadcasting', config('realtime.default', 'redis')],
        ];

        $this->table(['Driver', 'Value'], $rows);
    }

    private function printPhpInfo(): void
    {
        $this->info('PHP');
        $this->line();

        $extensions = [
            'pdo' => extension_loaded('pdo'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'pdo_pgsql' => extension_loaded('pdo_pgsql'),
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'redis' => extension_loaded('redis'),
            'pcntl' => extension_loaded('pcntl'),
            'posix' => extension_loaded('posix'),
            'curl' => extension_loaded('curl'),
            'openssl' => extension_loaded('openssl'),
            'mbstring' => extension_loaded('mbstring'),
            'json' => extension_loaded('json'),
        ];

        $rows = [];
        foreach ($extensions as $name => $loaded) {
            $rows[] = [$name, $loaded ? 'Yes' : 'No'];
        }

        $this->table(['Extension', 'Loaded'], $rows);
    }
}
