<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Contracts;


/**
 * Interface ClientRepositoryInterface
 *
 * Contract defining the interface for ClientRepositoryInterface
 * implementations in the OAuth2 layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  OAuth2\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ClientRepositoryInterface
{
    /**
     * Find a client by ID and secret.
     *
     * @param string $clientId Client ID
     * @param string|null $clientSecret Client secret (null for public clients)
     * @return ClientInterface|null Client or null if not found/invalid
     */
    public function findForAuthentication(string $clientId, ?string $clientSecret): ?ClientInterface;

    /**
     * Find a client by ID.
     *
     * @param string $clientId Client ID
     * @return ClientInterface|null Client or null if not found
     */
    public function findById(string $clientId): ?ClientInterface;

    /**
     * Create a new client.
     *
     * @param string $name Client name
     * @param string $redirectUri Redirect URI
     * @param bool $isConfidential Whether client is confidential (has secret)
     * @param array<string> $scopes Allowed scopes
     * @return ClientInterface Created client
     */
    public function create(string $name, string $redirectUri, bool $isConfidential = true, array $scopes = []): ClientInterface;
}
