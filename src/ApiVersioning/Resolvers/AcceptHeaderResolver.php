<?php

declare(strict_types=1);

namespace Toporia\Framework\ApiVersioning\Resolvers;

use Toporia\Framework\Http\Request;
use Toporia\Framework\ApiVersioning\Contracts\VersionResolverInterface;

/**
 * Class AcceptHeaderResolver
 *
 * Resolves API version from Accept header media type.
 * Example: Accept: application/vnd.api.v1+json
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class AcceptHeaderResolver implements VersionResolverInterface
{
    /**
     * @param string $vendor Vendor prefix (e.g., 'vnd.api')
     */
    public function __construct(
        private readonly string $vendor = 'vnd.api'
    ) {}

    /**
     * {@inheritdoc}
     */
    public function resolve(Request $request): ?string
    {
        $accept = $request->header('Accept');

        if ($accept === null || $accept === '') {
            return null;
        }

        // Pattern: application/vnd.api.v1+json
        $pattern = '/application\/' . preg_quote($this->vendor, '/') . '\.(v\d+)\+/';

        if (preg_match($pattern, $accept, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function priority(): int
    {
        return 85; // Between header and query
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'accept';
    }
}
