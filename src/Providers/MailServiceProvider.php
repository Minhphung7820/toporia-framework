<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Mail\Contracts\{MailManagerInterface, MailerInterface};
use Toporia\Framework\Mail\MailManager;
use Toporia\Framework\Queue\Contracts\QueueInterface;

/**
 * Class MailServiceProvider
 *
 * Registers mail services into the container with multi-driver support.
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
final class MailServiceProvider extends ServiceProvider
{
    // Note: Mail should NOT be deferred because:
    // 1. Queue workers need MailManagerInterface for SendEmailJob
    // 2. Deferred providers don't load properly in queue context

    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register MailManager (manages multiple drivers)
        $container->singleton(MailManager::class, function ($c) {
            $config = $c->get('config')->get('mail', []);

            // Get queue if available
            $queue = null;
            if ($c->has(QueueInterface::class)) {
                $queue = $c->get(QueueInterface::class);
            }

            return new MailManager($config, $queue);
        });

        // Bind MailManagerInterface
        $container->bind(MailManagerInterface::class, fn($c) => $c->get(MailManager::class));

        // Bind MailerInterface (uses default driver)
        $container->bind(MailerInterface::class, fn($c) => $c->get(MailManager::class));

        // Bind 'mailer' alias
        $container->bind('mailer', fn($c) => $c->get(MailManager::class));

        // Bind 'mail' alias
        $container->bind('mail', fn($c) => $c->get(MailManager::class));
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        // Nothing to boot
    }
}
