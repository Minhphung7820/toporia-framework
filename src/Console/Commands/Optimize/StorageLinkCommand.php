<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Optimize;

use Toporia\Framework\Console\Command;

/**
 * Class StorageLinkCommand
 *
 * Create symbolic link from public storage to storage.
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
final class StorageLinkCommand extends Command
{
    protected string $signature = 'storage:link {--relative : Create the symbolic link using relative paths} {--force : Recreate existing symbolic links}';

    protected string $description = 'Create the symbolic links configured for the application';

    public function handle(): int
    {
        $basePath = $this->getBasePath();
        $publicPath = $basePath . '/public/storage';
        $storagePath = $basePath . '/storage/app/public';

        // Create storage/app/public if it doesn't exist
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        // Check if link already exists
        if (file_exists($publicPath)) {
            if ($this->option('force')) {
                if (is_link($publicPath)) {
                    unlink($publicPath);
                } else {
                    $this->error("The [{$publicPath}] path already exists and is not a symbolic link.");
                    return 1;
                }
            } else {
                $this->error("The [{$publicPath}] link already exists.");
                return 1;
            }
        }

        // Create symbolic link
        if ($this->option('relative')) {
            $relativePath = $this->getRelativePath($publicPath, $storagePath);
            symlink($relativePath, $publicPath);
        } else {
            symlink($storagePath, $publicPath);
        }

        $this->success("The [public/storage] link has been connected to [storage/app/public].");

        return 0;
    }

    private function getRelativePath(string $from, string $to): string
    {
        $fromParts = explode('/', dirname($from));
        $toParts = explode('/', $to);

        // Remove common path
        while (count($fromParts) && count($toParts) && ($fromParts[0] === $toParts[0])) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        return str_repeat('../', count($fromParts)) . implode('/', $toParts);
    }

    private function getBasePath(): string
    {
        if (defined('APP_BASE_PATH')) {
            return constant('APP_BASE_PATH');
        }

        return getcwd() ?: dirname(__DIR__, 5);
    }
}
