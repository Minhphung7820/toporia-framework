<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Optimize;

use Toporia\Framework\Console\Command;

/**
 * Class ViewCacheCommand
 *
 * Compile all view templates.
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
final class ViewCacheCommand extends Command
{
    protected string $signature = 'view:cache';

    protected string $description = 'Compile all of the application\'s views';

    public function handle(): int
    {
        $viewsPath = $this->getBasePath() . '/resources/views';
        $cachePath = $this->getBasePath() . '/storage/framework/views';

        if (!is_dir($viewsPath)) {
            $this->error('Views directory not found.');
            return 1;
        }

        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        $compiled = 0;
        $files = $this->getViewFiles($viewsPath);

        foreach ($files as $file) {
            // For plain PHP views, we can copy them to cache with a hash name
            // This is a simplified implementation
            $relativePath = str_replace($viewsPath . '/', '', $file);
            $hash = sha1($relativePath);
            $cachedPath = $cachePath . '/' . $hash . '.php';

            // Copy the view file
            copy($file, $cachedPath);
            $compiled++;
        }

        $this->success("Compiled {$compiled} view(s).");

        return 0;
    }

    private function getViewFiles(string $path): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function getBasePath(): string
    {
        if (defined('APP_BASE_PATH')) {
            return constant('APP_BASE_PATH');
        }

        return getcwd() ?: dirname(__DIR__, 5);
    }
}
