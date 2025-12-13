<?php

declare(strict_types=1);

namespace Toporia\Framework\Application\Middleware;

use Closure;


/**
 * Interface MiddlewareInterface
 *
 * Contract defining the interface for MiddlewareInterface implementations
 * in the Middleware layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Middleware
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface MiddlewareInterface
{
    /**
     * Handle the middleware logic.
     *
     * @param object $message Command or Query object
     * @param Closure $next Next middleware or handler
     * @return mixed Result from next middleware or handler
     */
    public function handle(object $message, Closure $next): mixed;
}
