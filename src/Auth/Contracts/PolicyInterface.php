<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Contracts;


/**
 * Interface PolicyInterface
 *
 * Contract defining the interface for PolicyInterface implementations in
 * the Authentication and authorization layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface PolicyInterface
{
    /**
     * Perform pre-authorization checks (runs before all policy methods).
     *
     * If this method returns a non-null value, it will be used as the
     * authorization result and no other methods will be called.
     *
     * Use cases:
     * - Grant admin users full access
     * - Block banned users from all actions
     * - Skip expensive checks for super users
     *
     * @param mixed $user Authenticated user
     * @param string $ability Ability name
     * @return bool|null True = allow, False = deny, Null = continue to ability method
     */
    public function before(mixed $user, string $ability): ?bool;
}
