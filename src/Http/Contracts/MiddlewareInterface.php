<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Contracts;

use Toporia\Framework\Http\{Request, Response};


/**
 * Interface MiddlewareInterface
 *
 * Contract defining the interface for MiddlewareInterface implementations
 * in the HTTP request and response handling layer of the Toporia
 * Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface MiddlewareInterface
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request The HTTP request.
     * @param Response $response The HTTP response.
     * @param callable $next Next middleware/handler in the pipeline.
     * @return mixed
     */
    public function handle(Request $request, Response $response, callable $next): mixed;
}
