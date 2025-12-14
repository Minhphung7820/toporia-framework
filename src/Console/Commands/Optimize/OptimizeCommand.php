<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Optimize;

use Toporia\Framework\Console\Command;

/**
 * Class OptimizeCommand
 *
 * Optimize the application for better performance.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\Optimize
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class OptimizeCommand extends Command
{
    protected string $signature = 'optimize';

    protected string $description = 'Cache the framework bootstrap files';

    public function handle(): int
    {
        $this->info('Caching framework bootstrap files...');
        $this->newLine();

        // Config cache
        $this->info('Caching configuration...');
        $this->cacheConfig();

        // Route cache
        $this->info('Caching routes...');
        $this->cacheRoutes();

        // Event cache
        $this->info('Caching events...');
        $this->cacheEvents();

        // View cache (if applicable)
        $this->info('Caching views...');
        $this->cacheViews();

        $this->newLine();
        $this->success('Files cached successfully.');

        return 0;
    }

    private function cacheConfig(): void
    {
        $configPath = $this->getBasePath() . '/config';
        $cachePath = $this->getBasePath() . '/bootstrap/cache/config.php';

        if (!is_dir($configPath)) {
            $this->warn('  Config directory not found, skipping.');
            return;
        }

        $config = [];
        $files = glob($configPath . '/*.php');

        foreach ($files as $file) {
            $key = basename($file, '.php');
            $config[$key] = require $file;
        }

        $this->writeCache($cachePath, $config);
        $this->info('  Config cached successfully.');
    }

    private function cacheRoutes(): void
    {
        // Route caching would compile routes to a PHP file
        // This is a simplified implementation
        $this->info('  Route caching requires route:cache command.');
    }

    private function cacheEvents(): void
    {
        $cachePath = $this->getBasePath() . '/bootstrap/cache/events.php';

        // Check if events are already cached
        if (file_exists($cachePath)) {
            $this->info('  Events already cached.');
        } else {
            $this->info('  No events to cache.');
        }
    }

    private function cacheViews(): void
    {
        $viewsPath = $this->getBasePath() . '/resources/views';

        if (!is_dir($viewsPath)) {
            $this->info('  Views directory not found, skipping.');
            return;
        }

        // View compilation would compile Blade-like templates
        $this->info('  View caching not implemented (using plain PHP views).');
    }

    private function writeCache(string $path, array $data): void
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $content = '<?php return ' . var_export($data, true) . ';';
        file_put_contents($path, $content);
    }

        return getcwd() ?: dirname(__DIR__, 5);
    }
}
