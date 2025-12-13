<?php

declare(strict_types=1);

namespace Toporia\Framework\Application\UseCase;

use Toporia\Framework\Application\Contracts\HandlerInterface;


/**
 * Abstract Class AbstractHandler
 *
 * Abstract base class for AbstractHandler implementations in the UseCase
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
abstract class AbstractHandler implements HandlerInterface
{
    /**
     * Execute the use case.
     *
     * @param object $message Command or Query object
     * @return mixed Result of the operation
     */
    abstract public function __invoke(object $message): mixed;
}
