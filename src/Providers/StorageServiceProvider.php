<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Storage\StorageManager;

/**
 * Class StorageServiceProvider
 *
 * Registers the Storage system with multi-driver support.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Providers
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class StorageServiceProvider extends ServiceProvider
{
    /**
     * Register storage services.
     */
    public function register(ContainerInterface $container): void
    {
        // Register StorageManager as singleton
        $container->singleton(StorageManager::class, function ($c) {
            $config = $c->get('config')->get('filesystems', []);
            $defaultDisk = $config['default'] ?? 'local';

            return new StorageManager($config, $defaultDisk);
        });

        // Register 'storage' alias for easy access
        $container->bind('storage', fn($c) => $c->get(StorageManager::class));
    }

    /**
     * Bootstrap storage services.
     */
    public function boot(ContainerInterface $container): void
    {
        // Ensure storage directories exist
        $this->createStorageDirectories($container);
    }

    /**
     * Create storage directories if they don't exist.
     *
     * @param ContainerInterface $container
     * @return void
     */
    private function createStorageDirectories(ContainerInterface $container): void
    {
        $config = $container->get('config')->get('filesystems', []);
        $disks = $config['disks'] ?? [];

        foreach ($disks as $name => $diskConfig) {
            if (($diskConfig['driver'] ?? '') === 'local' && isset($diskConfig['root'])) {
                $root = $diskConfig['root'];

                if (!is_dir($root)) {
                    @mkdir($root, 0755, true);
                }
            }
        }
    }
}
