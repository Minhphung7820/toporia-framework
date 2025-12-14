<?php

declare(strict_types=1);

namespace Toporia\Framework\Audit\Middleware;

use Toporia\Framework\Audit\AuditManager;
use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Http\Request;
use Toporia\Framework\Http\Response;

/**
 * Class SetAuditContext
 *
 * Middleware to automatically set audit context from the current request.
 * Captures user info, IP address, user agent, and URL.
 *
 * Usage:
 *   // In middleware configuration
 *   'audit' => SetAuditContext::class
 *
 *   // In route
 *   $router->middleware(['auth', 'audit'])->group(...)
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class SetAuditContext implements MiddlewareInterface
{
    public function __construct(
        private readonly AuditManager $auditManager
    ) {}

    /**
     * Handle the request.
     *
     * @param Request $request
     * @param callable $next
     * @return Response
     */
    public function handle(Request $request, callable $next): Response
    {
        // Set request context
        $this->auditManager->setRequest(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            url: $request->fullUrl()
        );

        // Set user context if authenticated
        $user = $this->getAuthenticatedUser($request);

        if ($user !== null) {
            $this->auditManager->setUser(
                userId: $this->getUserId($user),
                userName: $this->getUserName($user)
            );
        }

        return $next($request);
    }

    /**
     * Get authenticated user from request.
     *
     * @param Request $request
     * @return object|null
     */
    protected function getAuthenticatedUser(Request $request): ?object
    {
        // Try request attribute
        $user = $request->getAttribute('user');

        if ($user !== null) {
            return $user;
        }

        // Try auth helper
        if (function_exists('auth')) {
            $auth = auth();

            if ($auth->check()) {
                return $auth->user();
            }
        }

        return null;
    }

    /**
     * Get user ID.
     *
     * @param object $user
     * @return int|string|null
     */
    protected function getUserId(object $user): int|string|null
    {
        if (method_exists($user, 'getKey')) {
            return $user->getKey();
        }

        if (method_exists($user, 'getId')) {
            return $user->getId();
        }

        if (property_exists($user, 'id')) {
            return $user->id;
        }

        return null;
    }

    /**
     * Get user name for display.
     *
     * @param object $user
     * @return string|null
     */
    protected function getUserName(object $user): ?string
    {
        // Try common name methods/properties
        $nameFields = ['name', 'full_name', 'fullName', 'username', 'email'];

        foreach ($nameFields as $field) {
            // Try method
            $method = 'get' . ucfirst($field);
            if (method_exists($user, $method)) {
                $value = $user->$method();
                if ($value !== null) {
                    return (string) $value;
                }
            }

            // Try property
            if (property_exists($user, $field) && $user->$field !== null) {
                return (string) $user->$field;
            }
        }

        return null;
    }
}
