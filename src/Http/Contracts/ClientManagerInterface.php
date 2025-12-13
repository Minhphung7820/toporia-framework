<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Contracts;

use Toporia\Framework\Http\Client\GraphQLClient;


/**
 * Interface ClientManagerInterface
 *
 * Contract defining the interface for ClientManagerInterface
 * implementations in the HTTP request and response handling layer of the
 * Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface ClientManagerInterface extends HttpClientInterface
{
    /**
     * Get HTTP client instance
     *
     * @param string|null $name Client name (null for default)
     * @return HttpClientInterface
     */
    public function client(?string $name = null): HttpClientInterface;

    /**
     * Get GraphQL client instance
     *
     * @param string|null $name Client name (null for default)
     * @return GraphQLClient
     */
    public function graphql(?string $name = null): GraphQLClient;

    /**
     * Get default client name
     *
     * @return string
     */
    public function getDefaultClient(): string;
}
