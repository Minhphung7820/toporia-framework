<?php

declare(strict_types=1);

namespace Toporia\Framework\Pipeline\Contracts;

use Closure;


/**
 * Interface PipelineInterface
 *
 * Contract defining the interface for PipelineInterface implementations in
 * the Pipeline layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Pipeline\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface PipelineInterface
{
    /**
     * Set the object being sent through the pipeline.
     *
     * @param mixed $passable The object to process
     * @return self
     */
    public function send(mixed $passable): self;

    /**
     * Set the array of pipes.
     *
     * @param array<int, mixed> $pipes Array of pipes (callables, classes, or objects)
     * @return self
     */
    public function through(array $pipes): self;

    /**
     * Add a pipe to the pipeline.
     *
     * @param mixed $pipe Pipe (callable, class name, or object)
     * @return self
     */
    public function pipe(mixed $pipe): self;

    /**
     * Conditionally add pipes to the pipeline.
     *
     * @param bool|Closure $condition Condition or callback returning bool
     * @param mixed|array $pipes Pipe(s) to add if condition is true
     * @param mixed|array|null $elsePipes Pipe(s) to add if condition is false
     * @return self
     */
    public function when(bool|Closure $condition, mixed $pipes, mixed $elsePipes = null): self;

    /**
     * Set the method to call on pipe objects.
     *
     * @param string $method Method name (default: 'handle')
     * @return self
     */
    public function via(string $method): self;

    /**
     * Set exception handler for pipeline failures.
     *
     * @param Closure $callback Exception handler: fn(Throwable $e, mixed $passable): mixed
     * @return self
     */
    public function onFailure(Closure $callback): self;

    /**
     * Set finally callback (always executed regardless of success/failure).
     *
     * @param Closure $callback Finally callback: fn(mixed $passable): void
     * @return self
     */
    public function finally(Closure $callback): self;

    /**
     * Run the pipeline with a final destination callback.
     *
     * @param Closure $destination Final callback to execute after all pipes
     * @return mixed Result from destination callback
     */
    public function then(Closure $destination): mixed;

    /**
     * Run the pipeline and return the result.
     *
     * Equivalent to ->then(fn($passable) => $passable)
     *
     * @return mixed The processed passable
     */
    public function thenReturn(): mixed;
}
