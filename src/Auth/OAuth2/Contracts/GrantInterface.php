<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\OAuth2\Contracts;

use Toporia\Framework\Http\Request;


/**
 * Interface GrantInterface
 *
 * Contract defining the interface for GrantInterface implementations in
 * the OAuth2 layer of the Toporia Framework.
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
interface GrantInterface
{
    /**
     * Get grant type identifier.
     *
     * @return string Grant type (e.g., 'authorization_code', 'client_credentials')
     */
    public function getIdentifier(): string;

    /**
     * Issue an access token for this grant type.
     *
     * @param Request $request HTTP request
     * @param ClientInterface $client OAuth2 client
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token?: string, scope?: string}
     * @throws \RuntimeException If token issuance fails
     */
    public function issueToken(Request $request, ClientInterface $client): array;
}
