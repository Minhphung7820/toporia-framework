<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Optimize;

use Toporia\Framework\Console\Command;

/**
 * Class OptimizeClearCommand
 *
 * Clear all cached optimizations.
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
final class OptimizeClearCommand extends Command
{
    protected string $signature = 'optimize:clear';

    protected string $description = 'Remove the cached bootstrap files';

    public function handle(): int
    {
        $this->info('Clearing cached bootstrap files...');
        $this->newLine();

        $cachePath = $this->getBasePath() . '/bootstrap/cache';

        $files = [
            'config.php' => 'Configuration',
            'routes.php' => 'Routes',
            'events.php' => 'Events',
            'services.php' => 'Services',
        ];

        foreach ($files as $file => $label) {
            $path = $cachePath . '/' . $file;

            if (file_exists($path)) {
                unlink($path);
                $this->info("  {$label} cache cleared.");
            }
        }

        $this->newLine();
        $this->success('Cached bootstrap files cleared successfully.');

        return 0;
    }

        return getcwd() ?: dirname(__DIR__, 5);
    }
}
