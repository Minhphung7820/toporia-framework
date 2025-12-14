<?php

declare(strict_types=1);

namespace Toporia\Framework\ApiVersioning\Contracts;

use Toporia\Framework\Http\Request;

/**
 * Interface VersionResolverInterface
 *
 * Contract for API version resolution strategies.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
interface VersionResolverInterface
{
    /**
     * Resolve API version from request.
     *
     * @param Request $request
     * @return string|null Version string (e.g., 'v1', '2024-01-01') or null if not found
     */
    public function resolve(Request $request): ?string;

    /**
     * Get resolver priority (higher = runs first).
     *
     * @return int
     */
    public function priority(): int;

    /**
     * Get resolver name.
     *
     * @return string
     */
    public function name(): string;
}
