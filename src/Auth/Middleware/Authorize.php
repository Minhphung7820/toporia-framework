<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Middleware;

use Toporia\Framework\Auth\AuthorizationException;
use Toporia\Framework\Auth\Contracts\GateContract;
use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Http\Exceptions\AccessDeniedHttpException;
use Toporia\Framework\Http\{Request, Response};

/**
 * Class Authorize
 *
 * Authorizes requests based on Gate abilities before reaching the controller.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Middleware
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Authorize implements MiddlewareInterface
{
    /**
     * Ability to check.
     *
     * @var string|null
     */
    private ?string $ability = null;

    /**
     * Multiple abilities to check.
     *
     * @var array<string>|null
     */
    private ?array $abilities = null;

    /**
     * Resource class for policy-based authorization.
     *
     * @var string|null
     */
    private ?string $resourceClass = null;

    /**
     * Route parameter name for resource.
     *
     * @var string|null
     */
    private ?string $resourceParam = null;

    /**
     * Check mode: 'any' or 'all' for multiple abilities.
     *
     * @var string
     */
    private string $mode = 'single';

    /**
     * Create middleware instance.
     *
     * @param GateContract $gate Gate instance
     */
    public function __construct(
        private readonly GateContract $gate
    ) {
    }

    /**
     * Create middleware for single ability.
     *
     * @param string $ability Ability name
     * @param string|null $resourceClass Resource class for policy
     * @param string|null $resourceParam Route parameter name
     * @return self Middleware instance
     */
    public static function using(
        string $ability,
        ?string $resourceClass = null,
        ?string $resourceParam = null
    ): self {
        $middleware = app(self::class);
        $middleware->ability = $ability;
        $middleware->resourceClass = $resourceClass;
        $middleware->resourceParam = $resourceParam;
        $middleware->mode = 'single';

        return $middleware;
    }

    /**
     * Create middleware requiring ANY of the abilities.
     *
     * @param array<string> $abilities Ability names
     * @return self Middleware instance
     */
    public static function any(array $abilities): self
    {
        $middleware = app(self::class);
        $middleware->abilities = $abilities;
        $middleware->mode = 'any';

        return $middleware;
    }

    /**
     * Create middleware requiring ALL of the abilities.
     *
     * @param array<string> $abilities Ability names
     * @return self Middleware instance
     */
    public static function all(array $abilities): self
    {
        $middleware = app(self::class);
        $middleware->abilities = $abilities;
        $middleware->mode = 'all';

        return $middleware;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request HTTP request
     * @param Response $response HTTP response
     * @param callable $next Next middleware
     * @return mixed Response or null to short-circuit
     */
    public function handle(Request $request, Response $response, callable $next): mixed
    {
        try {
            match ($this->mode) {
                'single' => $this->authorizeSingle($request),
                'any' => $this->authorizeAny($request),
                'all' => $this->authorizeAll($request),
            };
        } catch (AuthorizationException $e) {
            // Throw AccessDeniedHttpException - will be caught by error handler
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }

        // Authorized, continue
        return $next($request, $response);
    }

    /**
     * Authorize single ability.
     *
     * @param Request $request HTTP request
     * @return void
     * @throws AuthorizationException If denied
     */
    private function authorizeSingle(Request $request): void
    {
        $arguments = [];

        // Resolve resource from route if specified
        if ($this->resourceClass !== null && $this->resourceParam !== null) {
            $resource = $this->resolveResource($request);
            if ($resource !== null) {
                $arguments[] = $resource;
            }
        }

        $this->gate->authorize($this->ability, ...$arguments);
    }

    /**
     * Authorize ANY of the abilities.
     *
     * @param Request $request HTTP request
     * @return void
     * @throws AuthorizationException If all denied
     */
    private function authorizeAny(Request $request): void
    {
        if ($this->gate->any($this->abilities)) {
            return; // At least one allowed
        }

        throw new AuthorizationException(
            'None of the required permissions are granted: ' . implode(', ', $this->abilities)
        );
    }

    /**
     * Authorize ALL of the abilities.
     *
     * @param Request $request HTTP request
     * @return void
     * @throws AuthorizationException If any denied
     */
    private function authorizeAll(Request $request): void
    {
        foreach ($this->abilities as $ability) {
            $this->gate->authorize($ability);
        }
    }

    /**
     * Resolve resource from route parameter.
     *
     * @param Request $request HTTP request
     * @return mixed Resource instance or null
     */
    private function resolveResource(Request $request): mixed
    {
        // Get route parameter value
        $paramValue = $request->get($this->resourceParam);

        if ($paramValue === null) {
            return null;
        }

        // If resource class has find() method, use it
        if (method_exists($this->resourceClass, 'find')) {
            return $this->resourceClass::find($paramValue);
        }

        // Otherwise, just return the class name (for policy class-based checks)
        return $this->resourceClass;
    }
}
