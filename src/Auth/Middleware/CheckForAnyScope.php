<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Middleware;

use Toporia\Framework\Auth\AuthManager;
use Toporia\Framework\Auth\Contracts\HasApiTokensInterface;
use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Http\Exceptions\{UnauthorizedHttpException, AccessDeniedHttpException};
use Toporia\Framework\Http\{Request, Response};

/**
 * Class CheckForAnyScope
 *
 * Ensures the authenticated user's token has AT LEAST ONE of the specified abilities/scopes.
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
final class CheckForAnyScope implements MiddlewareInterface
{
    /**
     * Create middleware instance.
     *
     * @param AuthManager $auth Authentication manager
     * @param array<string> $scopes Required scopes (ANY must be present)
     */
    public function __construct(
        private readonly AuthManager $auth,
        private readonly array $scopes = []
    ) {
    }

    /**
     * Create middleware with required scopes.
     *
     * @param string ...$scopes Required scopes (ANY)
     * @return self Middleware instance
     */
    public static function requires(string ...$scopes): self
    {
        return new self(app('auth'), $scopes);
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
        // Get authenticated user
        $user = $this->auth->guard('personal-token')->user();

        if ($user === null || !$user instanceof HasApiTokensInterface) {
            // Throw UnauthorizedHttpException - will be caught by error handler
            throw new UnauthorizedHttpException('Bearer', 'Valid API token required');
        }

        // Check if token has ANY of the required scopes
        $hasAnyScope = false;

        foreach ($this->scopes as $scope) {
            if ($user->tokenCan($scope)) {
                $hasAnyScope = true;
                break;
            }
        }

        if (!$hasAnyScope) {
            // Throw AccessDeniedHttpException - will be caught by error handler
            throw new AccessDeniedHttpException(
                sprintf('Token lacks required permissions. Requires any of: %s', implode(', ', $this->scopes))
            );
        }

        // Has at least one scope, continue
        return $next($request, $response);
    }
}
