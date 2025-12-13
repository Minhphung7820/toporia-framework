<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Exceptions;

/**
 * Job Timeout Exception
 *
 * Thrown when a job exceeds its maximum execution time.
 *
 * Architecture:
 * - Clean exception hierarchy
 * - Provides timeout context for debugging
 *
 * Performance: O(1) - Simple exception creation
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 */
final class JobTimeoutException extends \RuntimeException
{
    public function __construct(
        string $jobId,
        int $timeout,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $message = sprintf(
            'Job %s exceeded maximum execution time of %d seconds',
            $jobId,
            $timeout
        );

        parent::__construct($message, $code, $previous);
    }
}
