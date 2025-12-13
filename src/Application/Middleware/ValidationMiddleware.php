<?php

declare(strict_types=1);

namespace Toporia\Framework\Application\Middleware;

use Closure;
use Toporia\Framework\Application\Contracts\{CommandInterface, QueryInterface};
use Toporia\Framework\Application\Exception\{CommandValidationException, QueryValidationException};

/**
 * Validation Middleware
 *
 * Automatically validates commands and queries before they reach handlers.
 * This middleware is typically added to the global middleware stack.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Application\Middleware
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ValidationMiddleware implements MiddlewareInterface
{
    /**
     * Handle validation middleware.
     *
     * @param object $message Command or Query object
     * @param Closure $next Next middleware or handler
     * @return mixed Result from next middleware or handler
     * @throws CommandValidationException|QueryValidationException If validation fails
     */
    public function handle(object $message, Closure $next): mixed
    {
        // Validate command
        if ($message instanceof CommandInterface) {
            try {
                $message->validate();
            } catch (\InvalidArgumentException $e) {
                throw new CommandValidationException(
                    ['_general' => $e->getMessage()],
                    $e->getMessage()
                );
            }
        }

        // Validate query
        if ($message instanceof QueryInterface) {
            try {
                $message->validate();
            } catch (\InvalidArgumentException $e) {
                throw new QueryValidationException(
                    ['_general' => $e->getMessage()],
                    $e->getMessage()
                );
            }
        }

        // Continue to next middleware or handler
        return $next($message);
    }
}

