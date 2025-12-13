<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\DateTime\Chronos;
use Toporia\Framework\DateTime\Contracts\ChronosInterface;
use Toporia\Framework\Foundation\ServiceProvider;

/**
 * Class DateTimeServiceProvider
 *
 * Registers date/time services into the container.
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
final class DateTimeServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // Register Chronos as the default date/time implementation
        $container->bind(ChronosInterface::class, fn() => Chronos::now());

        // Convenience binding
        $container->bind('chronos', fn() => Chronos::now());
    }
}
