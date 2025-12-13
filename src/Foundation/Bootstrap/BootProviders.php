<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation\Bootstrap;

use Toporia\Framework\Foundation\Application;

/**
 * Boot Providers
 *
 * Boots all registered service providers.
 * This is where event listeners are registered and routes are loaded.
 *
 * Clean Architecture:
 * - Single Responsibility: Only handles provider booting
 * - Late execution: Called after all providers are registered
 * - Performance: O(P) where P = number of providers
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Foundation\Bootstrap
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class BootProviders
{
    /**
     * Bootstrap provider booting.
     *
     * @param Application $app Application instance
     * @return void
     */
    public static function bootstrap(Application $app): void
    {
        $app->boot();
    }
}

