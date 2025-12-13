<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation\Bootstrap;

use Toporia\Framework\Foundation\Application;
use Toporia\Framework\Foundation\ServiceAccessor;

/**
 * Register Facades
 *
 * Sets the container instance for the ServiceAccessor system.
 * This enables static-like access to services (e.g., Route::get()).
 *
 * Clean Architecture:
 * - Single Responsibility: Only handles facade registration
 * - Early execution: Called before service providers boot
 * - Performance: O(1) - simple container binding
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
final class RegisterFacades
{
    /**
     * Bootstrap facade registration.
     *
     * @param Application $app Application instance
     * @return void
     */
    public static function bootstrap(Application $app): void
    {
        ServiceAccessor::setContainer($app->getContainer());
    }
}
