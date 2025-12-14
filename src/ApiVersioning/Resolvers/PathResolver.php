<?php

declare(strict_types=1);

namespace Toporia\Framework\ApiVersioning\Resolvers;

use Toporia\Framework\Http\Request;
use Toporia\Framework\ApiVersioning\Contracts\VersionResolverInterface;

/**
 * Class PathResolver
 *
 * Resolves API version from URL path.
 * Example: /api/v1/users -> v1
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class PathResolver implements VersionResolverInterface
{
    /**
     * @param string $prefix Path prefix before version (e.g., 'api')
     * @param string $pattern Regex pattern for version (default: v followed by digits)
     */
    public function __construct(
        private readonly string $prefix = 'api',
        private readonly string $pattern = '/^v\d+$/'
    ) {}

    /**
     * {@inheritdoc}
     */
    public function resolve(Request $request): ?string
    {
        $path = trim($request->getPath(), '/');
        $segments = explode('/', $path);

        // Find version segment after prefix
        $prefixFound = false;
        foreach ($segments as $segment) {
            if (!$prefixFound && strtolower($segment) === strtolower($this->prefix)) {
                $prefixFound = true;
                continue;
            }

            if ($prefixFound || $this->prefix === '') {
                if (preg_match($this->pattern, $segment)) {
                    return $segment;
                }
                break; // Version should be right after prefix
            }
        }

        // Also check first segment if no prefix required
        if ($this->prefix === '' && !empty($segments[0])) {
            if (preg_match($this->pattern, $segments[0])) {
                return $segments[0];
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function priority(): int
    {
        return 90;
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'path';
    }
}
