<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2;

use Toporia\Framework\Auth\OAuth2\Contracts\{ClientRepositoryInterface, GrantInterface, OAuth2ServerInterface, TokenRepositoryInterface};
use Toporia\Framework\Http\Request;

/**
 * Class OAuth2Server
 *
 * Full OAuth2 server implementation supporting multiple grant types.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\OAuth2
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class OAuth2Server implements OAuth2ServerInterface
{
    /**
     * @var array<string, GrantInterface> Registered grant types
     */
    private array $grants = [];

    /**
     * @param ClientRepositoryInterface $clientRepository Client repository
     * @param TokenRepositoryInterface $tokenRepository Token repository
     */
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private TokenRepositoryInterface $tokenRepository
    ) {}

    /**
     * Register a grant type.
     *
     * @param GrantInterface $grant Grant implementation
     * @return self
     */
    public function enableGrant(GrantInterface $grant): self
    {
        $this->grants[$grant->getIdentifier()] = $grant;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function issueAccessToken(Request $request): array
    {
        // Validate grant type
        $grantType = $request->input('grant_type');
        if ($grantType === null) {
            throw new \InvalidArgumentException('Missing required parameter: grant_type');
        }

        if (!isset($this->grants[$grantType])) {
            throw new \InvalidArgumentException("Unsupported grant type: {$grantType}");
        }

        // Authenticate client
        $clientId = $request->input('client_id');
        $clientSecret = $request->input('client_secret');

        if ($clientId === null) {
            throw new \InvalidArgumentException('Missing required parameter: client_id');
        }

        $client = $this->clientRepository->findForAuthentication($clientId, $clientSecret);
        if ($client === null) {
            throw new \RuntimeException('Invalid client credentials');
        }

        // Issue token using appropriate grant
        $grant = $this->grants[$grantType];
        return $grant->issueToken($request, $client);
    }

    /**
     * {@inheritdoc}
     */
    public function validateAccessToken(string $token): ?array
    {
        return $this->tokenRepository->validateAccessToken($token);
    }

    /**
     * {@inheritdoc}
     */
    public function revokeAccessToken(string $token): bool
    {
        return $this->tokenRepository->revokeAccessToken($token);
    }

    /**
     * {@inheritdoc}
     */
    public function revokeRefreshToken(string $token): bool
    {
        return $this->tokenRepository->revokeRefreshToken($token);
    }
}
