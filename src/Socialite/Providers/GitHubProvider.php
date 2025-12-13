<?php

declare(strict_types=1);

namespace Toporia\Framework\Socialite\Providers;

use Toporia\Framework\Socialite\AbstractProvider;
use Toporia\Framework\Socialite\User;
use Toporia\Framework\Http\Contracts\HttpClientInterface;

/**
 * Class GitHubProvider
 *
 * GitHub OAuth Provider
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
final class GitHubProvider extends AbstractProvider
{
    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl(): string
    {
        return 'https://github.com/login/oauth/authorize';
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl(): string
    {
        return 'https://github.com/login/oauth/access_token';
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserUrl(): string
    {
        return 'https://api.github.com/user';
    }

    /**
     * {@inheritdoc}
     */
    public function getUserFromToken(string $token): User
    {
        $user = $this->getUserData($token);

        // Get email separately if not included
        if (empty($user['email'])) {
            $emailResponse = $this->httpClient
                ->withToken($token)
                ->acceptJson()
                ->get('https://api.github.com/user/emails');

            if ($emailResponse->successful()) {
                $emails = $emailResponse->json();
                foreach ($emails as $email) {
                    if ($email['primary'] ?? false) {
                        $user['email'] = $email['email'];
                        break;
                    }
                }
            }
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
            name: $user['name'] ?? $user['login'] ?? '',
            email: $user['email'] ?? '',
            avatar: $user['avatar_url'] ?? null,
            nickname: $user['login'] ?? null,
            attributes: $user
        );
    }
}

