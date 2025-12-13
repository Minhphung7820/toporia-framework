<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation;

use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Class FrameworkServiceProvider
 *
 * Registers all framework-level service providers.
 * This keeps framework concerns separate from application concerns.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Foundation
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class FrameworkServiceProvider extends ServiceProvider
{
    /**
     * Get all framework service providers.
     *
     * @return array<class-string<ServiceProvider>>
     */
    public static function providers(): array
    {
        return [
            // Core services (order matters!)
            \Toporia\Framework\Providers\ConfigServiceProvider::class,
            \Toporia\Framework\Providers\HttpServiceProvider::class,
            \Toporia\Framework\Providers\ResponseServiceProvider::class,
            \Toporia\Framework\Providers\EventServiceProvider::class,
            \Toporia\Framework\Providers\RoutingServiceProvider::class,
            \Toporia\Framework\Providers\UrlServiceProvider::class,
            \Toporia\Framework\Providers\LogServiceProvider::class,
            \Toporia\Framework\Providers\DateTimeServiceProvider::class,
            \Toporia\Framework\Providers\TranslationServiceProvider::class,
            \Toporia\Framework\Providers\ConsoleServiceProvider::class,

            // Feature services
            \Toporia\Framework\Providers\AuthServiceProvider::class,
            \Toporia\Framework\Providers\HashServiceProvider::class,
            \Toporia\Framework\Providers\SecurityServiceProvider::class,
            \Toporia\Framework\Providers\SessionServiceProvider::class,
            \Toporia\Framework\Providers\CacheServiceProvider::class,
            \Toporia\Framework\Providers\QueueServiceProvider::class,
            \Toporia\Framework\Providers\BusServiceProvider::class,
            \Toporia\Framework\Providers\ScheduleServiceProvider::class,
            \Toporia\Framework\Providers\MailServiceProvider::class,
            \Toporia\Framework\Providers\HttpClientServiceProvider::class,
            \Toporia\Framework\Providers\DatabaseServiceProvider::class,
            \Toporia\Framework\Providers\StorageServiceProvider::class,
            \Toporia\Framework\Providers\NotificationServiceProvider::class,
            \Toporia\Framework\Providers\RealtimeServiceProvider::class,
            \Toporia\Framework\Providers\ConsumerServiceProvider::class,
            \Toporia\Framework\Providers\ConcurrencyServiceProvider::class,
            \Toporia\Framework\Providers\SearchServiceProvider::class,
            \Toporia\Framework\Providers\ObserverServiceProvider::class,
            \Toporia\Framework\Providers\ViteServiceProvider::class,
            \Toporia\Framework\Webhook\Providers\WebhookServiceProvider::class,
            \Toporia\Framework\Socialite\Providers\SocialiteServiceProvider::class,
        ];
    }

    /**
     * Register framework services.
     *
     * This method is not used - we use static providers() instead.
     */
    public function register(ContainerInterface $container): void
    {
        // Framework providers are registered via providers() method
    }
}
