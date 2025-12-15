<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Client\Middleware;

use Toporia\Framework\Http\Contracts\HttpResponseInterface;

/**
 * Interface HttpMiddlewareInterface
 *
 * Middleware for HTTP client requests and responses.
 * Allows transformation, logging, authentication injection, and custom behavior.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Client\Middleware
 * @since       2025-01-15
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface HttpMiddlewareInterface
{
    /**
     * Handle the HTTP request/response.
     *
     * @param HttpRequestContext $context Request context
     * @param callable $next Next middleware in chain
     * @return HttpResponseInterface
     */
    public function handle(HttpRequestContext $context, callable $next): HttpResponseInterface;
}
