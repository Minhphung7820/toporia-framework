<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Exceptions;


/**
 * Class RateLimitExceededException
 *
 * Exception class for handling RateLimitExceededException errors in the
 * Exceptions layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Exceptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class RateLimitExceededException extends \RuntimeException
{
    public function __construct(
        string $message = 'Rate limit exceeded',
        private int $retryAfter = 60,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get number of seconds until retry is allowed.
     *
     * @return int
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
