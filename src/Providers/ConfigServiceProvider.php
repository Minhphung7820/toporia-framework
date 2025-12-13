<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Config\Repository;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\{Application, ServiceProvider};


/**
 * Class ConfigServiceProvider
 *
 * Abstract base class for service providers responsible for registering
 * and booting framework services following two-phase lifecycle (register
 * then boot).
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
class ConfigServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // If config is already loaded (by LoadConfiguration), use it
        if ($container->has('config')) {
            return;
        }

        // Otherwise, load it now (backward compatibility)
        $container->singleton(Repository::class, function (ContainerInterface $c) {
            /** @var Application $app */
            $app = $c->get(Application::class);

            $config = new Repository();

            // Load all config files from config directory
            $configPath = $app->path('config');
            if (is_dir($configPath)) {
                $config->loadDirectory($configPath);
            }

            return $config;
        });

        $container->singleton('config', fn(ContainerInterface $c) => $c->get(Repository::class));
    }
}
