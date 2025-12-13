<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Grants;

use Toporia\Framework\Auth\Contracts\UserProviderInterface;
use Toporia\Framework\Auth\OAuth2\Contracts\{ClientInterface, GrantInterface, TokenRepositoryInterface};
use Toporia\Framework\Http\Request;


/**
 * Abstract Class AbstractGrant
 *
 * Abstract base class for AbstractGrant implementations in the Grants
 * layer providing common functionality and contracts.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Grants
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class AbstractGrant implements GrantInterface
{
    /**
     * @param TokenRepositoryInterface $tokenRepository Token repository
     * @param UserProviderInterface|null $userProvider User provider (for user-based grants)
     */
    public function __construct(
        protected TokenRepositoryInterface $tokenRepository,
        protected ?UserProviderInterface $userProvider = null
    ) {}

    /**
     * {@inheritdoc}
     */
    abstract public function getIdentifier(): string;

    /**
     * {@inheritdoc}
     */
    abstract public function issueToken(Request $request, ClientInterface $client): array;

    /**
     * Validate required parameters for grant type.
     *
     * @param Request $request HTTP request
     * @param array<string> $required Required parameter names
     * @return void
     * @throws \InvalidArgumentException If required parameters are missing
     */
    protected function validateRequiredParameters(Request $request, array $required): void
    {
        foreach ($required as $param) {
            if ($request->input($param) === null) {
                throw new \InvalidArgumentException("Missing required parameter: {$param}");
            }
        }
    }

    /**
     * Get token expiration time from request or use default.
     *
     * @param Request $request HTTP request
     * @param int $default Default expiration in seconds
     * @return int Expiration time in seconds
     */
    protected function getExpiresIn(Request $request, int $default = 3600): int
    {
        $expiresIn = $request->input('expires_in');
        return $expiresIn !== null ? (int) $expiresIn : $default;
    }

    /**
     * Get scopes from request or use default.
     *
     * @param Request $request HTTP request
     * @param array<string> $default Default scopes
     * @return array<string> Requested scopes
     */
    protected function getScopes(Request $request, array $default = []): array
    {
        $scopes = $request->input('scope');
        if ($scopes === null || $scopes === '') {
            return $default;
        }

        return is_array($scopes) ? $scopes : explode(' ', $scopes);
    }
}
