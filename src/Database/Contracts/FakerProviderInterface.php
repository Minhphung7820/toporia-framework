<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Contracts;

use Faker\Generator;


/**
 * Interface FakerProviderInterface
 *
 * Contract defining the interface for FakerProviderInterface
 * implementations in the Database query building and ORM layer of the
 * Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface FakerProviderInterface
{
    /**
     * Register custom formatters with the Faker generator.
     *
     * @param Generator $generator
     * @return void
     */
    public function register(Generator $generator): void;
}

