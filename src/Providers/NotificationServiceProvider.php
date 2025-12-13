<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Notification\NotificationManager;
use Toporia\Framework\Notification\Contracts\NotificationManagerInterface;

/**
 * Class NotificationServiceProvider
 *
 * Registers notification services with multi-channel support.
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
final class NotificationServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register NotificationManager (manages multiple channels)
        $container->singleton(NotificationManager::class, function ($c) {
            $config = $c->has('config')
                ? $c->get('config')->get('notification', [])
                : $this->getDefaultConfig();

            return new NotificationManager($config, $c);
        });

        // Register notification interface bindings
        $container->bind(NotificationManagerInterface::class, fn($c) => $c->get(NotificationManager::class));
        $container->bind('notification', fn($c) => $c->get(NotificationManager::class));
    }

    /**
     * Get default notification configuration.
     *
     * @return array
     */
    private function getDefaultConfig(): array
    {
        return [
            'default' => 'mail',
            'channels' => [
                'mail' => [
                    'driver' => 'mail',
                ],
                'database' => [
                    'driver' => 'database',
                    'table' => 'notifications',
                ],
            ],
        ];
    }
}
