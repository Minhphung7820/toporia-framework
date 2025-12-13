<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Access;

use Toporia\Framework\Auth\AuthorizationException;
use Toporia\Framework\Auth\Contracts\GateContract;
use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Class Gate
 *
 * Professional authorization gate implementation with performance optimization.
 *
 * Clean Architecture:
 * - Framework layer implementation
 * - Implements GateContract interface
 *
 * SOLID Principles:
 * - Single Responsibility: Authorization checking
 * - Dependency Inversion: Depends on ContainerInterface abstraction
 * - Open/Closed: Extensible via before/after callbacks
 *
 * Performance Optimizations:
 * - Lazy policy instantiation (only when needed)
 * - Result caching per request (memoization)
 * - O(1) ability/policy lookup with hashmaps
 * - Short-circuit evaluation (before callbacks)
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Access
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Gate implements GateContract
{
    /**
     * Registered abilities map.
     *
     * @var array<string, callable|string>
     */
    private array $abilities = [];

    /**
     * Registered policies map.
     *
     * @var array<string, string> Resource class => Policy class
     */
    private array $policies = [];

    /**
     * Before callbacks (run before ability checks).
     *
     * @var array<callable>
     */
    private array $beforeCallbacks = [];

    /**
     * After callbacks (run after ability checks).
     *
     * @var array<callable>
     */
    private array $afterCallbacks = [];

    /**
     * Resolved policy instances (singleton per request).
     *
     * @var array<string, object>
     */
    private array $policyInstances = [];

    /**
     * Authorization result cache (memoization).
     *
     * @var array<string, Response>
     */
    private array $resultCache = [];

    /**
     * Current user for authorization.
     *
     * @var mixed
     */
    private mixed $user = null;

    /**
     * Default denial response.
     *
     * @var Response
     */
    private Response $defaultDenialResponse;

    /**
     * Create gate instance.
     *
     * @param ContainerInterface $container DI container for policy resolution
     * @param callable|null $userResolver Callback to resolve current user
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private $userResolver = null
    ) {
        $this->defaultDenialResponse = Response::deny('This action is unauthorized.');
    }

    /**
     * {@inheritDoc}
     */
    public function define(string $ability, callable|string $callback): self
    {
        $this->abilities[$ability] = $callback;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function policy(string $class, string $policy): self
    {
        $this->policies[$class] = $policy;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function before(callable $callback): self
    {
        $this->beforeCallbacks[] = $callback;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function after(callable $callback): self
    {
        $this->afterCallbacks[] = $callback;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function allows(string $ability, mixed ...$arguments): bool
    {
        return $this->check($ability, ...$arguments)->allowed();
    }

    /**
     * {@inheritDoc}
     */
    public function denies(string $ability, mixed ...$arguments): bool
    {
        return $this->check($ability, ...$arguments)->denied();
    }

    /**
     * {@inheritDoc}
     *
     * Performance: O(1) with result cache, O(N) on cache miss where N = callbacks
     */
    public function check(string $ability, mixed ...$arguments): Response
    {
        $user = $this->resolveUser();

        if ($user === null) {
            return $this->defaultDenialResponse;
        }

        // Check result cache (memoization for repeated checks)
        $cacheKey = $this->getCacheKey($ability, $arguments);
        if (isset($this->resultCache[$cacheKey])) {
            return $this->resultCache[$cacheKey];
        }

        // 1. Run before callbacks (short-circuit if returns non-null)
        foreach ($this->beforeCallbacks as $callback) {
            $result = $callback($user, $ability, $arguments);

            if ($result !== null) {
                $response = $this->normalizeResponse($result);
                return $this->cacheResult($cacheKey, $response);
            }
        }

        // 2. Check ability (explicit definition or policy method)
        $response = $this->callAuthCallback($user, $ability, $arguments);

        // 3. Run after callbacks (can override result)
        foreach ($this->afterCallbacks as $callback) {
            $result = $callback($user, $ability, $response, $arguments);

            if ($result !== null) {
                $response = $this->normalizeResponse($result);
            }
        }

        return $this->cacheResult($cacheKey, $response);
    }

    /**
     * {@inheritDoc}
     */
    public function any(array $abilities, mixed ...$arguments): bool
    {
        foreach ($abilities as $ability) {
            if ($this->allows($ability, ...$arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function all(array $abilities, mixed ...$arguments): bool
    {
        foreach ($abilities as $ability) {
            if ($this->denies($ability, ...$arguments)) {
                return false;
            }
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function none(array $abilities, mixed ...$arguments): bool
    {
        return !$this->any($abilities, ...$arguments);
    }

    /**
     * {@inheritDoc}
     */
    public function authorize(string $ability, mixed ...$arguments): Response
    {
        return $this->check($ability, ...$arguments)->authorize();
    }

    /**
     * {@inheritDoc}
     */
    public function inspect(string $ability, mixed ...$arguments): Response
    {
        return $this->check($ability, ...$arguments);
    }

    /**
     * {@inheritDoc}
     */
    public function forUser(mixed $user): self
    {
        $gate = clone $this;
        $gate->user = $user;
        $gate->resultCache = []; // Clear cache for new user

        return $gate;
    }

    /**
     * {@inheritDoc}
     */
    public function abilities(): array
    {
        return $this->abilities;
    }

    /**
     * {@inheritDoc}
     */
    public function policies(): array
    {
        return $this->policies;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $ability): bool
    {
        return isset($this->abilities[$ability]);
    }

    /**
     * {@inheritDoc}
     */
    public function defaultDenialResponse(): Response
    {
        return $this->defaultDenialResponse;
    }

    /**
     * {@inheritDoc}
     */
    public function setDefaultDenialResponse(Response $response): self
    {
        $this->defaultDenialResponse = $response;

        return $this;
    }

    /**
     * Call the authorization callback for an ability.
     *
     * Performance:
     * - O(1) hashmap lookup for ability
     * - O(1) hashmap lookup for policy
     * - Lazy policy instantiation
     *
     * @param mixed $user User instance
     * @param string $ability Ability name
     * @param array $arguments Arguments
     * @return Response Authorization response
     */
    private function callAuthCallback(mixed $user, string $ability, array $arguments): Response
    {
        // 1. Check explicit ability definition
        if (isset($this->abilities[$ability])) {
            $callback = $this->abilities[$ability];
            $result = $this->callCallback($callback, $user, $arguments);

            return $this->normalizeResponse($result);
        }

        // 2. Check policy-based authorization
        $resource = $arguments[0] ?? null;

        if ($resource !== null) {
            $resourceClass = is_object($resource) ? get_class($resource) : $resource;

            if (isset($this->policies[$resourceClass])) {
                $result = $this->callPolicyMethod(
                    $this->policies[$resourceClass],
                    $ability,
                    $user,
                    $arguments
                );

                if ($result !== null) {
                    return $this->normalizeResponse($result);
                }
            }
        }

        // 3. No ability or policy found, return default denial
        return $this->defaultDenialResponse;
    }

    /**
     * Call a policy method.
     *
     * Performance: O(1) with policy instance cache
     *
     * @param string $policyClass Policy class name
     * @param string $method Method name (ability)
     * @param mixed $user User instance
     * @param array $arguments Arguments
     * @return mixed Authorization result
     */
    private function callPolicyMethod(string $policyClass, string $method, mixed $user, array $arguments): mixed
    {
        // Get or create policy instance (singleton per request)
        if (!isset($this->policyInstances[$policyClass])) {
            $this->policyInstances[$policyClass] = $this->container->get($policyClass);
        }

        $policy = $this->policyInstances[$policyClass];

        // Call before() method if exists (short-circuit)
        if (method_exists($policy, 'before')) {
            $result = $policy->before($user, $method);

            if ($result !== null) {
                return $result;
            }
        }

        // Call specific ability method
        if (method_exists($policy, $method)) {
            return $policy->{$method}($user, ...$arguments);
        }

        return null;
    }

    /**
     * Call a callback with user and arguments.
     *
     * @param callable|string $callback Callback or class@method
     * @param mixed $user User instance
     * @param array $arguments Arguments
     * @return mixed Callback result
     */
    private function callCallback(callable|string $callback, mixed $user, array $arguments): mixed
    {
        if (is_string($callback) && str_contains($callback, '@')) {
            [$class, $method] = explode('@', $callback, 2);
            $callback = [$this->container->get($class), $method];
        }

        return $callback($user, ...$arguments);
    }

    /**
     * Normalize authorization result to Response object.
     *
     * @param mixed $result Authorization result
     * @return Response Normalized response
     */
    private function normalizeResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_bool($result)) {
            return $result ? Response::allow() : $this->defaultDenialResponse;
        }

        // Null/other values = deny
        return $this->defaultDenialResponse;
    }

    /**
     * Resolve the current user.
     *
     * @return mixed User instance or null
     */
    private function resolveUser(): mixed
    {
        if ($this->user !== null) {
            return $this->user;
        }

        if ($this->userResolver !== null) {
            return ($this->userResolver)();
        }

        return null;
    }

    /**
     * Generate cache key for result memoization.
     *
     * Performance: O(N) where N = number of arguments
     *
     * @param string $ability Ability name
     * @param array $arguments Arguments
     * @return string Cache key
     */
    private function getCacheKey(string $ability, array $arguments): string
    {
        $key = $ability;

        foreach ($arguments as $arg) {
            if (is_object($arg)) {
                $key .= ':' . get_class($arg) . ':' . spl_object_id($arg);
            } elseif (is_scalar($arg)) {
                $key .= ':' . $arg;
            }
        }

        return $key;
    }

    /**
     * Cache authorization result.
     *
     * @param string $cacheKey Cache key
     * @param Response $response Response to cache
     * @return Response Same response
     */
    private function cacheResult(string $cacheKey, Response $response): Response
    {
        $this->resultCache[$cacheKey] = $response;

        return $response;
    }
}
