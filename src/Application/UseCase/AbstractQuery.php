<?php

declare(strict_types=1);

namespace Toporia\Framework\Application\UseCase;

use Toporia\Framework\Application\Contracts\QueryInterface;


/**
 * Abstract Class AbstractQuery
 *
 * Abstract base class for AbstractQuery implementations in the UseCase
 * layer providing common functionality and contracts.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  UseCase
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class AbstractQuery implements QueryInterface
{
    /**
     * Validate the query parameters.
     *
     * Override this method to add custom validation.
     * Default implementation does nothing.
     *
     * @return void
     * @throws \InvalidArgumentException If validation fails
     */
    public function validate(): void
    {
        // Override in subclasses for custom validation
    }
}
