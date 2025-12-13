<?php

declare(strict_types=1);

namespace Toporia\Framework\Socialite\Providers;

use Toporia\Framework\Socialite\AbstractProvider;
use Toporia\Framework\Socialite\User;
use Toporia\Framework\Http\Contracts\HttpClientInterface;

/**
 * Class GoogleProvider
 *
 * Google OAuth Provider
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Socialite\Providers
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class GoogleProvider extends AbstractProvider
{
    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserUrl(): string
    {
        return 'https://www.googleapis.com/oauth2/v2/userinfo';
    }

    /**
     * {@inheritdoc}
     */
    public function getUserFromToken(string $token): User
    {
        $user = $this->getUserData($token);

        return $this->mapUserToObject($user);
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user): User
    {
        return new User(
            id: (string) ($user['id'] ?? ''),
            name: $user['name'] ?? '',
            email: $user['email'] ?? '',
            avatar: $user['picture'] ?? null,
            nickname: $user['given_name'] ?? null,
            attributes: $user
        );
    }
}

