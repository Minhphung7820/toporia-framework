<?php

declare(strict_types=1);

namespace Toporia\Framework\ApiVersioning\Middleware;

use Toporia\Framework\Http\Middleware\AbstractMiddleware;
use Toporia\Framework\Http\Request;
use Toporia\Framework\Http\Response;
use Toporia\Framework\ApiVersioning\ApiVersion;

/**
 * Class EnsureApiVersion
 *
 * Middleware to ensure API version meets requirements.
 *
 * Usage:
 *   // Require specific version
 *   $router->get('/v2-only', [...])->middleware(['api.version.require:v2']);
 *
 *   // Require minimum version
 *   $router->get('/v2-plus', [...])->middleware(['api.version.min:v2']);
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class EnsureApiVersion extends AbstractMiddleware
{
    /**
     * @param string|null $exactVersion Require exact version
     * @param string|null $minVersion Require minimum version
     * @param string|null $maxVersion Require maximum version
     */
    public function __construct(
        private readonly ?string $exactVersion = null,
        private readonly ?string $minVersion = null,
        private readonly ?string $maxVersion = null
    ) {}

    /**
     * {@inheritdoc}
     */
    protected function process(Request $request, Response $response): ?Response
    {
        $current = ApiVersion::current();

        // Check exact version
        if ($this->exactVersion !== null && !ApiVersion::is($this->exactVersion)) {
            return $this->versionMismatch($response, $current, "exactly {$this->exactVersion}");
        }

        // Check minimum version
        if ($this->minVersion !== null && !ApiVersion::isAtLeast($this->minVersion)) {
            return $this->versionMismatch($response, $current, "at least {$this->minVersion}");
        }

        // Check maximum version
        if ($this->maxVersion !== null && !ApiVersion::isAtMost($this->maxVersion)) {
            return $this->versionMismatch($response, $current, "at most {$this->maxVersion}");
        }

        return null;
    }

    /**
     * Return version mismatch response.
     *
     * @param Response $response
     * @param string $current
     * @param string $requirement
     * @return Response
     */
    private function versionMismatch(Response $response, string $current, string $requirement): Response
    {
        $response->json([
            'success' => false,
            'error' => "API version {$current} does not meet requirement: {$requirement}",
            'code' => 'API_VERSION_MISMATCH',
            'current_version' => $current,
            'supported_versions' => ApiVersion::getSupportedVersions(),
        ], 400);

        return $response;
    }

    /**
     * Create middleware for exact version.
     *
     * @param string $version
     * @return self
     */
    public static function exact(string $version): self
    {
        return new self(exactVersion: $version);
    }

    /**
     * Create middleware for minimum version.
     *
     * @param string $version
     * @return self
     */
    public static function min(string $version): self
    {
        return new self(minVersion: $version);
    }

    /**
     * Create middleware for maximum version.
     *
     * @param string $version
     * @return self
     */
    public static function max(string $version): self
    {
        return new self(maxVersion: $version);
    }

    /**
     * Create middleware for version range.
     *
     * @param string $min
     * @param string $max
     * @return self
     */
    public static function between(string $min, string $max): self
    {
        return new self(minVersion: $min, maxVersion: $max);
    }
}
