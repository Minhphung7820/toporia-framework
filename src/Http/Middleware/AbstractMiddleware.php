<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Middleware;

use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Http\{Request, Response};


/**
 * Abstract Class AbstractMiddleware
 *
 * Base middleware class for processing HTTP requests in a pipeline pattern
 * with before/after hooks and request/response modification.
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
abstract class AbstractMiddleware implements MiddlewareInterface
{
    /**
     * Make the middleware invokable.
     *
     * @param Request $request
     * @param Response $response
     * @param callable $next
     * @return mixed
     */
    final public function __invoke(Request $request, Response $response, callable $next): mixed
    {
        return $this->handle($request, $response, $next);
    }

    /**
     * Handle the request with automatic before/after hooks.
     *
     * Flow:
     * 1. Call before() hook
     * 2. Call process() - if it returns Response, short-circuit
     * 3. Call $next() to continue pipeline
     * 4. Call after() hook with result
     * 5. Return result
     *
     * @param Request $request
     * @param Response $response
     * @param callable $next
     * @return mixed
     */
    final public function handle(Request $request, Response $response, callable $next): mixed
    {
        // Execute before hook
        $this->before($request, $response);

        // Process - can short-circuit by returning Response
        $processResult = $this->process($request, $response);
        if ($processResult !== null) {
            // Short-circuit: don't call next, just run after hook
            $this->after($request, $response, $processResult);
            return $processResult;
        }

        // Continue to next middleware/handler
        $result = $next($request, $response);

        // Execute after hook
        $this->after($request, $response, $result);

        return $result;
    }

    /**
     * Execute logic before passing to next middleware.
     *
     * Use this for:
     * - Logging request start
     * - Starting timers
     * - Setting request context
     *
     * @param Request $request
     * @param Response $response
     * @return void
     */
    protected function before(Request $request, Response $response): void
    {
        // Override in child class if needed
    }

    /**
     * Process the request with optional short-circuit.
     *
     * Return null to continue to next middleware.
     * Return Response to short-circuit (skip next middleware).
     *
     * Use this for:
     * - Authentication checks (return 401 response to block)
     * - Rate limiting (return 429 response to block)
     * - Request validation (return 400 response to block)
     * - Adding headers/modifying request before next handler
     *
     * @param Request $request
     * @param Response $response
     * @return mixed|null Return Response to short-circuit, null to continue.
     */
    protected function process(Request $request, Response $response): mixed
    {
        // Override in child class if needed
        return null; // Continue to next by default
    }

    /**
     * Execute logic after next middleware has run.
     *
     * Use this for:
     * - Logging response
     * - Modifying response headers
     * - Measuring execution time
     * - Cleanup actions
     *
     * Note: This runs even if process() short-circuits!
     *
     * @param Request $request
     * @param Response $response
     * @param mixed $result Result from next middleware or from process().
     * @return void
     */
    protected function after(Request $request, Response $response, mixed $result): void
    {
        // Override in child class if needed
    }
}
