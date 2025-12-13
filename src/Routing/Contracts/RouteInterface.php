<?php

declare(strict_types=1);

namespace Toporia\Framework\Routing\Contracts;


/**
 * Interface RouteInterface
 *
 * Contract defining the interface for RouteInterface implementations in
 * the HTTP routing and URL generation layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Routing\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface RouteInterface
{
    /**
     * Get the HTTP method(s) for this route.
     *
     * @return string|array Single method or array of methods.
     */
    public function getMethods(): string|array;

    /**
     * Get the URI pattern for this route.
     *
     * @return string
     */
    public function getUri(): string;

    /**
     * Get the route handler (callable, controller action, etc.).
     *
     * @return mixed
     */
    public function getHandler(): mixed;

    /**
     * Get middleware assigned to this route.
     *
     * @return array<string>
     */
    public function getMiddleware(): array;

    /**
     * Get the route name if set.
     *
     * @return string|null
     */
    public function getName(): ?string;

    /**
     * Set the route name for later reference.
     *
     * @param string $name
     * @return self
     */
    public function name(string $name): self;

    /**
     * Add middleware to this route.
     *
     * @param string|array $middleware
     * @return self
     */
    public function middleware(string|array $middleware): self;

    /**
     * Check if this route matches the given method and URI.
     *
     * @param string $method HTTP method.
     * @param string $uri Request URI.
     * @return array|null Array with parameters if matched, null otherwise.
     */
    public function matches(string $method, string $uri): ?array;
}
