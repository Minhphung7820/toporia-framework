<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Exceptions;

/**
 * Class TokenMismatchException
 *
 * 419 Token Mismatch HTTP Exception (CSRF). Used when CSRF token validation fails.
 * Status 419 is a non-standard HTTP status used by frameworks to indicate CSRF token mismatch/expired session.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Exceptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class TokenMismatchException extends HttpException
{
    public function __construct(
        string $message = 'CSRF token mismatch',
        ?\Throwable $previous = null
    ) {
        parent::__construct(419, $message, [], $previous);
    }
}
