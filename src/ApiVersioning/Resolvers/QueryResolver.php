<?php

declare(strict_types=1);

namespace Toporia\Framework\ApiVersioning\Resolvers;

use Toporia\Framework\Http\Request;
use Toporia\Framework\ApiVersioning\Contracts\VersionResolverInterface;

/**
 * Class QueryResolver
 *
 * Resolves API version from query string.
 * Example: /api/users?api_version=v1
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class QueryResolver implements VersionResolverInterface
{
    /**
     * @param string $paramName Query parameter name
     */
    public function __construct(
        private readonly string $paramName = 'api_version'
    ) {}

    /**
     * {@inheritdoc}
     */
    public function resolve(Request $request): ?string
    {
        $version = $request->input($this->paramName);

        if ($version !== null && $version !== '') {
            return trim((string) $version);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function priority(): int
    {
        return 80; // Lower than header and path
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'query';
    }
}
