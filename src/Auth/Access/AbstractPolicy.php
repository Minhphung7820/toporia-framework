<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Access;

use Toporia\Framework\Auth\Contracts\PolicyInterface;


/**
 * Abstract Class AbstractPolicy
 *
 * Authorization policy class for resource-specific permission logic
 * following convention-based method naming (view, create, update, delete).
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Access
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class AbstractPolicy implements PolicyInterface
{
    /**
     * Perform pre-authorization checks (runs before all policy methods).
     *
     * Override this method to implement common authorization logic:
     * - Grant admin/super-user full access
     * - Block banned/suspended users
     * - Check subscription status
     *
     * @param mixed $user Authenticated user
     * @param string $ability Ability name
     * @return bool|null True = allow, False = deny, Null = continue to ability method
     */
    public function before(mixed $user, string $ability): ?bool
    {
        // Default: no pre-authorization, continue to ability method
        return null;
    }

    /**
     * Determine if the user is an admin.
     *
     * Helper method for common "isAdmin" check.
     * Override to customize admin detection logic.
     *
     * @param mixed $user User instance
     * @return bool True if admin
     */
    protected function isAdmin(mixed $user): bool
    {
        if (method_exists($user, 'isAdmin')) {
            return $user->isAdmin();
        }

        if (isset($user->is_admin)) {
            return (bool) $user->is_admin;
        }

        if (isset($user->role) && $user->role === 'admin') {
            return true;
        }

        return false;
    }

    /**
     * Determine if the user owns the resource.
     *
     * Helper method for common ownership check.
     *
     * @param mixed $user User instance
     * @param mixed $resource Resource instance
     * @param string $ownerKey Owner key (default: 'user_id')
     * @return bool True if user owns resource
     */
    protected function owns(mixed $user, mixed $resource, string $ownerKey = 'user_id'): bool
    {
        if (!is_object($resource)) {
            return false;
        }

        $ownerId = $resource->{$ownerKey} ?? null;

        if ($ownerId === null) {
            return false;
        }

        $userId = $user->id ?? $user->getId() ?? null;

        return $userId !== null && $userId === $ownerId;
    }

    /**
     * Create an "allow" response with optional message.
     *
     * @param string|null $message Success message
     * @return Response Allowed response
     */
    protected function allow(?string $message = null): Response
    {
        return Response::allow($message);
    }

    /**
     * Create a "deny" response with optional message/code.
     *
     * @param string|null $message Denial reason
     * @param mixed $code Error code
     * @return Response Denied response
     */
    protected function deny(?string $message = null, mixed $code = null): Response
    {
        return Response::deny($message, $code);
    }
}
