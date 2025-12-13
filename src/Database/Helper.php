<?php

declare(strict_types=1);

namespace Toporia\Framework\Database;

use Toporia\Framework\Database\Contracts\FactoryInterface;
use Toporia\Framework\Database\ORM\Model;


/**
 * Class Helper
 *
 * Core class for the Database query building and ORM layer providing
 * essential functionality for the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
class Helper
{
    /**
     * Create a factory instance.
     *
     * @param class-string<FactoryInterface> $factoryClass
     * @return FactoryInterface
     */
    public static function factory(string $factoryClass): FactoryInterface
    {
        if (!is_subclass_of($factoryClass, FactoryInterface::class)) {
            throw new \InvalidArgumentException(
                "Factory class [{$factoryClass}] must implement " . FactoryInterface::class
            );
        }

        return $factoryClass::new();
    }
}

/**
 * Global helper function: factory()
 *
 * Creates a factory instance for convenient usage.
 *
 * @param class-string<FactoryInterface> $factoryClass
 * @return FactoryInterface
 */
function factory(string $factoryClass): FactoryInterface
{
    return Helper::factory($factoryClass);
}

