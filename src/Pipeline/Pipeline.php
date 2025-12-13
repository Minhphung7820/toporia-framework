<?php

declare(strict_types=1);

namespace Toporia\Framework\Pipeline;

use Closure;
use Throwable;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Pipeline\Contracts\PipelineInterface;

/**
 * Class Pipeline
 *
 * Pipeline implementation for chainable operations.
 * Perfect for filtering, transforming, validating data through multiple steps.
 *
 * Features:
 * - Pipe through callbacks (closures)
 * - Pipe through invokable objects
 * - Pipe through class methods (Class@method)
 * - Pipe with parameters (Class:param1,param2)
 * - Container-based dependency injection
 * - Fluent chainable API
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Pipeline
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Pipeline implements PipelineInterface
{
    /**
     * @var mixed The object being passed through the pipeline
     */
    private mixed $passable;

    /**
     * @var array<int, mixed> Array of pipes (callables, class names, or objects)
     */
    private array $pipes = [];

    /**
     * @var string Method name to call on pipe objects
     */
    private string $method = 'handle';

    /**
     * @var Closure|null Exception handler callback
     */
    private ?Closure $exceptionHandler = null;

    /**
     * @var Closure|null Finally callback (always executed)
     */
    private ?Closure $finallyCallback = null;

    /**
     * @param ContainerInterface|null $container DI container for resolving pipes
     */
    public function __construct(
        private ?ContainerInterface $container = null
    ) {}

    /**
     * Create a new pipeline instance.
     *
     * @param ContainerInterface|null $container DI container
     * @return self
     */
    public static function make(?ContainerInterface $container = null): self
    {
        return new self($container);
    }

    /**
     * Set the object being sent through the pipeline.
     *
     * @param mixed $passable The object to process
     * @return self
     */
    public function send(mixed $passable): self
    {
        $this->passable = $passable;
        return $this;
    }

    /**
     * Set the array of pipes.
     *
     * @param array<int, mixed> $pipes Array of pipes (callables, classes, or objects)
     * @return self
     */
    public function through(array $pipes): self
    {
        $this->pipes = $pipes;
        return $this;
    }

    /**
     * Add a pipe to the pipeline.
     *
     * @param mixed $pipe Pipe (callable, class name, or object)
     * @return self
     */
    public function pipe(mixed $pipe): self
    {
        $this->pipes[] = $pipe;
        return $this;
    }

    /**
     * Conditionally add pipes to the pipeline.
     *
     * @param bool|Closure $condition Condition or callback returning bool
     * @param mixed|array $pipes Pipe(s) to add if condition is true
     * @param mixed|array|null $elsePipes Pipe(s) to add if condition is false
     * @return self
     *
     * @example
     * ```php
     * Pipeline::make()
     *     ->send($data)
     *     ->when($user->isAdmin(), AdminPipe::class)
     *     ->when(
     *         fn($passable) => $passable->needsValidation(),
     *         [ValidatePipe::class, SanitizePipe::class]
     *     )
     *     ->thenReturn();
     * ```
     */
    public function when(bool|Closure $condition, mixed $pipes, mixed $elsePipes = null): self
    {
        // Evaluate condition if it's a closure
        $shouldAdd = $condition instanceof Closure
            ? $condition($this->passable ?? null)
            : $condition;

        $pipesToAdd = $shouldAdd ? $pipes : $elsePipes;

        if ($pipesToAdd !== null) {
            $pipesToAdd = is_array($pipesToAdd) ? $pipesToAdd : [$pipesToAdd];
            $this->pipes = array_merge($this->pipes, $pipesToAdd);
        }

        return $this;
    }

    /**
     * Set the method to call on pipe objects.
     *
     * @param string $method Method name (default: 'handle')
     * @return self
     */
    public function via(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    /**
     * Set exception handler for pipeline failures.
     *
     * @param Closure $callback Exception handler: fn(Throwable $e, mixed $passable): mixed
     * @return self
     *
     * @example
     * ```php
     * Pipeline::make()
     *     ->send($data)
     *     ->through($pipes)
     *     ->onFailure(function (Throwable $e, $passable) {
     *         Log::error('Pipeline failed', ['error' => $e->getMessage()]);
     *         return $passable; // Return original data on failure
     *     })
     *     ->thenReturn();
     * ```
     */
    public function onFailure(Closure $callback): self
    {
        $this->exceptionHandler = $callback;
        return $this;
    }

    /**
     * Set finally callback (always executed regardless of success/failure).
     *
     * @param Closure $callback Finally callback: fn(mixed $passable): void
     * @return self
     *
     * @example
     * ```php
     * Pipeline::make()
     *     ->send($data)
     *     ->through($pipes)
     *     ->finally(fn($passable) => $this->releaseResources())
     *     ->thenReturn();
     * ```
     */
    public function finally(Closure $callback): self
    {
        $this->finallyCallback = $callback;
        return $this;
    }

    /**
     * Run the pipeline with a final destination callback.
     *
     * @param Closure $destination Final callback to execute after all pipes
     * @return mixed Result from destination callback
     * @throws Throwable If exception occurs and no handler is set
     */
    public function then(Closure $destination): mixed
    {
        $pipeline = $this->buildPipeline($destination);

        try {
            $result = $pipeline($this->passable);
            return $result;
        } catch (Throwable $e) {
            // If exception handler is set, use it
            if ($this->exceptionHandler !== null) {
                return ($this->exceptionHandler)($e, $this->passable);
            }
            // Re-throw if no handler
            throw $e;
        } finally {
            // Always execute finally callback if set
            if ($this->finallyCallback !== null) {
                ($this->finallyCallback)($this->passable);
            }
        }
    }

    /**
     * Run the pipeline and return the result.
     *
     * Equivalent to ->then(fn($passable) => $passable)
     *
     * @return mixed The processed passable
     */
    public function thenReturn(): mixed
    {
        return $this->then(fn($passable) => $passable);
    }

    /**
     * Build the pipeline with all pipes.
     *
     * Builds pipeline in reverse order (onion pattern) so pipes execute
     * in declaration order.
     *
     * @param Closure $destination Final destination callback
     * @return Closure Pipeline function
     */
    private function buildPipeline(Closure $destination): Closure
    {
        // Start with destination as innermost layer
        $pipeline = $destination;

        // Wrap each pipe around the pipeline (reverse order for correct execution)
        foreach (array_reverse($this->pipes) as $pipe) {
            $pipeline = $this->wrapPipe($pipe, $pipeline);
        }

        return $pipeline;
    }

    /**
     * Wrap a single pipe around the next layer.
     *
     * @param mixed $pipe Pipe (callable, class name, or object)
     * @param Closure $next Next layer in the pipeline
     * @return Closure Wrapped function
     */
    private function wrapPipe(mixed $pipe, Closure $next): Closure
    {
        return function ($passable) use ($pipe, $next) {
            // If pipe is already a closure, execute directly
            if ($pipe instanceof Closure) {
                return $pipe($passable, $next);
            }

            // If pipe is an object with the specified method
            if (is_object($pipe) && method_exists($pipe, $this->method)) {
                return $pipe->{$this->method}($passable, $next);
            }

            // If pipe is a class name string
            if (is_string($pipe)) {
                return $this->executePipeClass($pipe, $passable, $next);
            }

            // If pipe is invokable object
            if (is_callable($pipe)) {
                return $pipe($passable, $next);
            }

            throw new \InvalidArgumentException(
                'Pipeline pipe must be a callable, invokable object, or class name. Got: ' . gettype($pipe)
            );
        };
    }

    /**
     * Execute a pipe class from string name.
     *
     * Supports:
     * - Plain class: 'MyPipe'
     * - Class with method: 'MyPipe@customMethod'
     * - Class with parameters: 'MyPipe:param1,param2'
     * - Class with method and parameters: 'MyPipe@customMethod:param1,param2'
     *
     * @param string $pipe Class name with optional method/parameters
     * @param mixed $passable Object being passed through
     * @param Closure $next Next layer
     * @return mixed Result from pipe
     */
    private function executePipeClass(string $pipe, mixed $passable, Closure $next): mixed
    {
        // Parse pipe string into components
        [$class, $method, $parameters] = $this->parsePipeString($pipe);

        // Resolve instance from container or instantiate directly
        $instance = $this->container !== null
            ? $this->container->get($class)
            : new $class();

        // Call the method with parameters
        return $instance->{$method}($passable, $next, ...$parameters);
    }

    /**
     * Parse pipe string into class, method, and parameters.
     *
     * Supported formats:
     * - 'MyPipe' -> ['MyPipe', 'handle', []]
     * - 'MyPipe@method' -> ['MyPipe', 'method', []]
     * - 'MyPipe:a,b' -> ['MyPipe', 'handle', ['a', 'b']]
     * - 'MyPipe@method:a,b' -> ['MyPipe', 'method', ['a', 'b']]
     *
     * @param string $pipe Pipe string
     * @return array{0: string, 1: string, 2: array} [className, methodName, parameters]
     */
    private function parsePipeString(string $pipe): array
    {
        $class = $pipe;
        $method = $this->method;
        $parameters = [];

        // Extract parameters first (after :)
        if (str_contains($pipe, ':')) {
            [$pipe, $paramString] = explode(':', $pipe, 2);
            $parameters = str_contains($paramString, ',')
                ? array_map('trim', explode(',', $paramString))
                : [$paramString];
            $class = $pipe;
        }

        // Extract method (after @)
        if (str_contains($class, '@')) {
            [$class, $method] = explode('@', $class, 2);
        }

        return [$class, $method, $parameters];
    }

    /**
     * Get the container instance.
     *
     * @return ContainerInterface|null
     */
    public function getContainer(): ?ContainerInterface
    {
        return $this->container;
    }

    /**
     * Set the container instance.
     *
     * @param ContainerInterface $container
     * @return self
     */
    public function setContainer(ContainerInterface $container): self
    {
        $this->container = $container;
        return $this;
    }
}
