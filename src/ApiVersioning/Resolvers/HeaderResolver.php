<?php

declare(strict_types=1);

namespace Toporia\Framework\ApiVersioning\Resolvers;

use Toporia\Framework\Http\Request;
use Toporia\Framework\ApiVersioning\Contracts\VersionResolverInterface;

/**
 * Class HeaderResolver
 *
 * Resolves API version from HTTP headers.
 * Supports: X-API-Version, Accept-Version, API-Version
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class HeaderResolver implements VersionResolverInterface
{
    /**
     * @param array<string> $headerNames Headers to check (in order)
     */
    public function __construct(
        private readonly array $headerNames = ['X-API-Version', 'Accept-Version', 'API-Version']
    ) {}

    /**
     * {@inheritdoc}
     */
    public function resolve(Request $request): ?string
    {
        foreach ($this->headerNames as $header) {
            $value = $request->header($header);

            if ($value !== null && $value !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function priority(): int
    {
        return 100; // Highest priority
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'header';
    }
}
