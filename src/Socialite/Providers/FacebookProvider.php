<?php

declare(strict_types=1);

namespace Toporia\Framework\Socialite\Providers;

use Toporia\Framework\Socialite\AbstractProvider;
use Toporia\Framework\Socialite\User;
use Toporia\Framework\Http\Contracts\HttpClientInterface;

/**
 * Class FacebookProvider
 *
 * Facebook OAuth Provider
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
final class FacebookProvider extends AbstractProvider
{
    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl(): string
    {
        return 'https://www.facebook.com/v18.0/dialog/oauth';
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl(): string
    {
        return 'https://graph.facebook.com/v18.0/oauth/access_token';
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserUrl(): string
    {
        return 'https://graph.facebook.com/v18.0/me?fields=id,name,email,picture';
    }

    /**
     * {@inheritdoc}
     */
    public function getUserFromToken(string $token): User
    {
        $user = $this->getUserData($token);

        // Get picture separately if needed
        if (isset($user['picture']['data']['url'])) {
            $user['avatar'] = $user['picture']['data']['url'];
        }

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
            avatar: $user['avatar'] ?? null,
            nickname: $user['name'] ?? null,
            attributes: $user
        );
    }
}

