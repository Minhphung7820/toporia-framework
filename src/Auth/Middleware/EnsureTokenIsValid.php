<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Middleware;

use Toporia\Framework\Auth\AuthManager;
use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Http\Exceptions\UnauthorizedHttpException;
use Toporia\Framework\Http\{Request, Response};

/**
 * Class EnsureTokenIsValid
 *
 * Validates that the request contains a valid, non-expired API token.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Middleware
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class EnsureTokenIsValid implements MiddlewareInterface
{
    /**
     * Create middleware instance.
     *
     * @param AuthManager $auth Authentication manager
     */
    public function __construct(
        private readonly AuthManager $auth
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request HTTP request
     * @param Response $response HTTP response
     * @param callable $next Next middleware
     * @return mixed Response or null to short-circuit
     */
    public function handle(Request $request, Response $response, callable $next): mixed
    {
        // Authenticate user via token
        $user = $this->auth->guard('personal-token')->user();

        if ($user === null) {
            // Throw UnauthorizedHttpException - will be caught by error handler
            throw new UnauthorizedHttpException('Bearer', 'Valid API token required');
        }

        // Continue to next middleware
        return $next($request, $response);
    }
}
