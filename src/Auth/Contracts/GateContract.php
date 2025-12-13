<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Contracts;

use Toporia\Framework\Auth\Access\Response;


/**
 * Interface GateContract
 *
 * Contract defining the interface for GateContract implementations in the
 * Authentication and authorization layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface GateContract
{
    /**
     * Define a new ability.
     *
     * @param string $ability Ability name (e.g., 'update-post')
     * @param callable|string $callback Callback or policy class method
     * @return self
     */
    public function define(string $ability, callable|string $callback): self;

    /**
     * Register a policy for a resource class.
     *
     * @param string $class Resource class (e.g., Post::class)
     * @param string $policy Policy class (e.g., PostPolicy::class)
     * @return self
     */
    public function policy(string $class, string $policy): self;

    /**
     * Define abilities before callbacks (run before policy methods).
     *
     * @param callable $callback Callback($user, $ability) => bool|null
     * @return self
     */
    public function before(callable $callback): self;

    /**
     * Define abilities after callbacks (run after policy methods).
     *
     * @param callable $callback Callback($user, $ability, $result) => bool|null
     * @return self
     */
    public function after(callable $callback): self;

    /**
     * Determine if the user can perform the given ability.
     *
     * @param string $ability Ability name
     * @param mixed ...$arguments Arguments (typically resource instance)
     * @return bool True if allowed
     */
    public function allows(string $ability, mixed ...$arguments): bool;

    /**
     * Determine if the user cannot perform the given ability.
     *
     * @param string $ability Ability name
     * @param mixed ...$arguments Arguments
     * @return bool True if denied
     */
    public function denies(string $ability, mixed ...$arguments): bool;

    /**
     * Determine if the user can perform the given ability (with Response).
     *
     * @param string $ability Ability name
     * @param mixed ...$arguments Arguments
     * @return Response Authorization response with message
     */
    public function check(string $ability, mixed ...$arguments): Response;

    /**
     * Determine if any of the given abilities are granted.
     *
     * @param array<string> $abilities Ability names
     * @param mixed ...$arguments Arguments
     * @return bool True if any allowed
     */
    public function any(array $abilities, mixed ...$arguments): bool;

    /**
     * Determine if all of the given abilities are granted.
     *
     * @param array<string> $abilities Ability names
     * @param mixed ...$arguments Arguments
     * @return bool True if all allowed
     */
    public function all(array $abilities, mixed ...$arguments): bool;

    /**
     * Determine if none of the given abilities are granted.
     *
     * @param array<string> $abilities Ability names
     * @param mixed ...$arguments Arguments
     * @return bool True if none allowed
     */
    public function none(array $abilities, mixed ...$arguments): bool;

    /**
     * Authorize an ability or throw exception.
     *
     * @param string $ability Ability name
     * @param mixed ...$arguments Arguments
     * @return Response Authorization response
     * @throws AuthorizationException If denied
     */
    public function authorize(string $ability, mixed ...$arguments): Response;

    /**
     * Inspect the user for the given ability.
     *
     * Returns detailed Response object with allow/deny reason.
     *
     * @param string $ability Ability name
     * @param mixed ...$arguments Arguments
     * @return Response Authorization response
     */
    public function inspect(string $ability, mixed ...$arguments): Response;

    /**
     * Get a gate instance for the given user.
     *
     * @param \Toporia\Framework\Auth\Contracts\Authenticatable|mixed $user User instance
     * @return self New gate instance for user
     */
    public function forUser(mixed $user): self;

    /**
     * Get all defined abilities.
     *
     * @return array<string, callable|string> Abilities map
     */
    public function abilities(): array;

    /**
     * Get all registered policies.
     *
     * @return array<string, string> Policies map
     */
    public function policies(): array;

    /**
     * Determine if a given ability has been defined.
     *
     * @param string $ability Ability name
     * @return bool True if defined
     */
    public function has(string $ability): bool;

    /**
     * Get the default denial response.
     *
     * @return Response Default denial response
     */
    public function defaultDenialResponse(): Response;

    /**
     * Set the default denial response.
     *
     * @param Response $response Response instance
     * @return self
     */
    public function setDefaultDenialResponse(Response $response): self;
}
