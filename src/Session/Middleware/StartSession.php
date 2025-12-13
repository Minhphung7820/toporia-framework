<?php

declare(strict_types=1);

namespace Toporia\Framework\Session\Middleware;

use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Http\{Request, Response};
use Toporia\Framework\Session\Store;

/**
 * Class StartSession
 *
 * Middleware that starts session on demand (lazy loading).
 *
 * This middleware should be added to the 'web' middleware group.
 * API routes typically don't need session, so they skip this middleware
 * for better performance.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Session\Middleware
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class StartSession implements MiddlewareInterface
{
    public function __construct(
        private Store $session
    ) {}

    /**
     * Handle the request.
     *
     * Starts session at the beginning of request and saves at the end.
     *
     * @param Request $request
     * @param Response $response
     * @param callable $next
     * @return mixed
     */
    public function handle(Request $request, Response $response, callable $next): mixed
    {
        // Start session (lazy - only starts if not already started)
        $this->session->start();

        // Store session in request for easy access
        $request->setSession($this->session);

        // Process request
        $result = $next($request, $response);

        // Save session data after response is generated
        $this->session->save();

        return $result;
    }
}
