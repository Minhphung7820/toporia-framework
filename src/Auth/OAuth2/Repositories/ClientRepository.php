<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Repositories;

use Toporia\Framework\Auth\OAuth2\Contracts\{ClientInterface, ClientRepositoryInterface};
use Toporia\Framework\Auth\OAuth2\Models\OAuth2Client;

/**
 * Class ClientRepository
 *
 * Manages OAuth2 clients (applications).
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\OAuth2\Repositories
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ClientRepository implements ClientRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function findForAuthentication(string $clientId, ?string $clientSecret): ?ClientInterface
    {
        $client = OAuth2Client::where('client_id', $clientId)->first();

        if ($client === null) {
            return null;
        }

        // Public clients don't require secret
        if (!$client->isConfidential()) {
            return $client;
        }

        // Confidential clients must provide and verify secret
        if ($clientSecret === null || !$client->verifySecret($clientSecret)) {
            return null;
        }

        return $client;
    }

    /**
     * {@inheritdoc}
     */
    public function findById(string $clientId): ?ClientInterface
    {
        return OAuth2Client::where('client_id', $clientId)->first();
    }

    /**
     * {@inheritdoc}
     */
    public function create(string $name, string $redirectUri, bool $isConfidential = true, array $scopes = []): ClientInterface
    {
        $clientId = OAuth2Client::generateClientId();
        $clientSecret = $isConfidential ? OAuth2Client::generateClientSecret() : null;
        $hashedSecret = $clientSecret !== null ? OAuth2Client::hashSecret($clientSecret) : null;

        $client = OAuth2Client::create([
            'name' => $name,
            'client_id' => $clientId,
            'client_secret' => $hashedSecret,
            'redirect_uri' => $redirectUri,
            'is_confidential' => $isConfidential,
            'scopes' => $scopes ?: ['*'], // Default to all scopes
        ]);

        // Store plain text secret temporarily for return (only in memory)
        // In real implementation, return NewClient object with plain text secret
        return $client;
    }
}
