<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth;

use Toporia\Framework\Auth\Authenticatable;
use Toporia\Framework\Auth\Contracts\{AuthManagerInterface, GuardInterface};

/**
 * Class AuthManager
 *
 * Manages multiple authentication guards implementing the Strategy pattern
 * for different authentication mechanisms. Follows Open/Closed Principle -
 * extensible with custom guards.
 *
 * Usage:
 * ```php
 * $authManager->guard('web')->attempt($credentials);
 * $authManager->guard('api')->user();
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class AuthManager implements AuthManagerInterface
{
    /**
     * @var array<string, GuardInterface> Resolved guard instances
     */
    private array $guards = [];

    /**
     * @var array<string, callable> Guard factories
     */
    private array $customCreators = [];

    /**
     * @param array<string, callable> $guardFactories Guard factory callbacks.
     * @param string $defaultGuard Default guard name.
     */
    public function __construct(
        private array $guardFactories = [],
        private string $defaultGuard = 'web'
    ) {}

    /**
     * {@inheritdoc}
     */
    public function guard(?string $name = null): GuardInterface
    {
        $name = $name ?? $this->defaultGuard;

        // Return cached instance if exists
        if (isset($this->guards[$name])) {
            return $this->guards[$name];
        }

        // Create new instance
        $this->guards[$name] = $this->resolve($name);

        return $this->guards[$name];
    }

    /**
     * {@inheritdoc}
     */
    public function setDefaultGuard(string $name): void
    {
        $this->defaultGuard = $name;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultGuard(): string
    {
        return $this->defaultGuard;
    }

    /**
     * {@inheritdoc}
     */
    public function hasGuard(string $name): bool
    {
        return isset($this->guardFactories[$name]) || isset($this->customCreators[$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function extend(string $name, callable $callback): void
    {
        $this->customCreators[$name] = $callback;

        // Clear cached instance if exists
        unset($this->guards[$name]);
    }

    /**
     * Resolve a guard instance.
     *
     * @param string $name Guard name.
     * @return GuardInterface
     * @throws \InvalidArgumentException If guard not found.
     */
    private function resolve(string $name): GuardInterface
    {
        // Try custom creator first
        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])($name);
        }

        // Try configured factory
        if (isset($this->guardFactories[$name])) {
            return ($this->guardFactories[$name])();
        }

        throw new \InvalidArgumentException("Auth guard [{$name}] is not defined.");
    }

    /**
     * Proxy method calls to default guard.
     *
     * This allows using AuthManager as a Guard directly.
     *
     * @param string $method Method name.
     * @param array<mixed> $parameters Parameters.
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->guard()->{$method}(...$parameters);
    }

    // =========================================================================
    // CONVENIENCE METHODS (for static access via Auth accessor)
    // =========================================================================

    /**
     * Check if user is authenticated (convenience method).
     *
     * @return bool
     */
    public function check(): bool
    {
        return $this->guard()->check();
    }

    /**
     * Check if user is guest (convenience method).
     *
     * @return bool
     */
    public function guest(): bool
    {
        return $this->guard()->guest();
    }

    /**
     * Get authenticated user (convenience method).
     *
     * @return \Toporia\Framework\Auth\Authenticatable|null
     */
    public function user(): ?Authenticatable
    {
        return $this->guard()->user();
    }

    /**
     * Get authenticated user ID (convenience method).
     *
     * @return int|string|null
     */
    public function id(): int|string|null
    {
        return $this->guard()->id();
    }

    /**
     * Attempt authentication (convenience method).
     *
     * @param array<string, mixed> $credentials
     * @return bool
     */
    public function attempt(array $credentials): bool
    {
        return $this->guard()->attempt($credentials);
    }

    /**
     * Login user (convenience method).
     *
     * @param \Toporia\Framework\Auth\Authenticatable $user
     * @return void
     */
    public function login(Authenticatable $user): void
    {
        $this->guard()->login($user);
    }

    /**
     * Logout user (convenience method).
     *
     * @return void
     */
    public function logout(): void
    {
        $this->guard()->logout();
    }
}
