<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Auth\Contracts\{AuthManagerInterface, GuardInterface};
use Toporia\Framework\Auth\Authenticatable;

/**
 * Class Auth
 *
 * Auth Service Accessor - Provides static-like access to the authentication manager.
 * All methods are automatically delegated to the underlying service via __callStatic().
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Accessors
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @method static GuardInterface guard(?string $name = null) Get guard instance
 * @method static void setDefaultGuard(string $name) Set default guard
 * @method static string getDefaultGuard() Get default guard name
 * @method static bool hasGuard(string $name) Check if guard exists
 * @method static bool check() Check if user is authenticated
 * @method static bool guest() Check if user is guest
 * @method static Authenticatable|null user() Get authenticated user
 * @method static int|string|null id() Get authenticated user ID
 * @method static bool attempt(array $credentials) Attempt authentication
 * @method static void login(Authenticatable $user) Login user
 * @method static void logout() Logout user
 *
 * @see AuthManagerInterface
 *
 * @example
 * // Get default guard and use it
 * if (Auth::guard()->check()) {
 *     $user = Auth::guard()->user();
 * }
 *
 * // Attempt login
 * if (Auth::guard()->attempt(['email' => $email, 'password' => $password])) {
 *     // Success
 * }
 *
 * // Logout
 * Auth::guard()->logout();
 *
 * // Use specific guard
 * $token = Auth::guard('api')->user();
 */
final class Auth extends ServiceAccessor
{
    /**
     * Get the service name for this accessor.
     *
     * This is the only method needed - all other methods are automatically
     * delegated to the underlying service via __callStatic().
     *
     * @return string Service name in container
     */
    protected static function getServiceName(): string
    {
        return 'auth';
    }
}
