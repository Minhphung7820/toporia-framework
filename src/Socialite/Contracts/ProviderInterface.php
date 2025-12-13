<?php

declare(strict_types=1);

namespace Toporia\Framework\Socialite\Contracts;

use Toporia\Framework\Http\Request;
use Toporia\Framework\Socialite\User;

/**
 * Interface ProviderInterface
 *
 * Contract for OAuth providers (Google, Facebook, GitHub, etc.)
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Socialite\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ProviderInterface
{
    /**
     * Redirect user to OAuth provider.
     *
     * @param Request|null $request HTTP request
     * @return string Redirect URL
     */
    public function redirect(?Request $request = null): string;

    /**
     * Handle OAuth callback and get user data.
     *
     * @param Request $request HTTP request with OAuth callback data
     * @return \Toporia\Framework\Socialite\User User data
     */
    public function user(Request $request): User;

    /**
     * Get access token from callback.
     *
     * @param Request $request HTTP request
     * @return string Access token
     */
    public function getAccessToken(Request $request): string;

    /**
     * Get user data using access token.
     *
     * @param string $token Access token
     * @return \Toporia\Framework\Socialite\User User data
     */
    public function getUserFromToken(string $token): User;
}

