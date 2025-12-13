<?php

declare(strict_types=1);

namespace Toporia\Framework\Webhook\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Http\Contracts\HttpClientInterface;
use Toporia\Framework\Log\Contracts\LoggerInterface;
use Toporia\Framework\Queue\Contracts\QueueManagerInterface;
use Toporia\Framework\Webhook\{WebhookDispatcher, WebhookReceiver, WebhookManager, SignatureGenerator};
use Toporia\Framework\Webhook\Contracts\{WebhookDispatcherInterface, WebhookReceiverInterface, SignatureGeneratorInterface};

/**
 * Class WebhookServiceProvider
 *
 * Registers webhook services.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Webhook\Providers
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class WebhookServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     */
    protected bool $defer = true;

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            SignatureGeneratorInterface::class,
            WebhookDispatcherInterface::class,
            WebhookReceiverInterface::class,
            WebhookManager::class,
            'webhook',
            'webhook.dispatcher',
            'webhook.receiver',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register signature generator
        $container->singleton(SignatureGeneratorInterface::class, function ($c) {
            $config = $c->has('config')
                ? $c->get('config')->get('webhook', [])
                : [];

            $algorithm = $config['signature_algorithm'] ?? 'sha256';

            return new SignatureGenerator($algorithm);
        });

        // Register webhook dispatcher
        $container->singleton(WebhookDispatcherInterface::class, function ($c) {
            return new WebhookDispatcher(
                $c->get(HttpClientInterface::class),
                $c->get(SignatureGeneratorInterface::class),
                $c->has(QueueManagerInterface::class)
                    ? $c->get(QueueManagerInterface::class)
                    : null,
                $c->has(LoggerInterface::class)
                    ? $c->get(LoggerInterface::class)
                    : null
            );
        });

        // Register webhook receiver
        $container->singleton(WebhookReceiverInterface::class, function ($c) {
            return new WebhookReceiver(
                $c->get(SignatureGeneratorInterface::class),
                $c->has(LoggerInterface::class)
                    ? $c->get(LoggerInterface::class)
                    : null
            );
        });

        // Register webhook manager
        $container->singleton(WebhookManager::class, function ($c) {
            return new WebhookManager(
                $c->get(WebhookDispatcherInterface::class)
            );
        });

        // Bind aliases
        $container->bind('webhook', fn($c) => $c->get(WebhookManager::class));
        $container->bind('webhook.dispatcher', fn($c) => $c->get(WebhookDispatcherInterface::class));
        $container->bind('webhook.receiver', fn($c) => $c->get(WebhookReceiverInterface::class));
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        // Webhook services are ready
    }
}

