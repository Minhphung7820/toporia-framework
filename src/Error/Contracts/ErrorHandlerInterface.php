<?php

declare(strict_types=1);

namespace Toporia\Framework\Error\Contracts;

use Throwable;


/**
 * Interface ErrorHandlerInterface
 *
 * Contract defining the interface for ErrorHandlerInterface
 * implementations in the Error handling layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Error\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ErrorHandlerInterface
{
    /**
     * Handle an exception.
     *
     * @param Throwable $exception The exception to handle
     * @return void
     */
    public function handle(Throwable $exception): void;

    /**
     * Report an exception to logging/monitoring system.
     *
     * @param Throwable $exception The exception to report
     * @return void
     */
    public function report(Throwable $exception): void;

    /**
     * Render an exception as HTTP response.
     *
     * @param Throwable $exception The exception to render
     * @return void
     */
    public function render(Throwable $exception): void;

    /**
     * Register the error handler.
     *
     * @return void
     */
    public function register(): void;
}
