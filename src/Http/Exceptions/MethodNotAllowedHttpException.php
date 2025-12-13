<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Exceptions;

/**
 * Class MethodNotAllowedHttpException
 *
 * 405 Method Not Allowed HTTP Exception.
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
class MethodNotAllowedHttpException extends HttpException
{
    /**
     * @param array<string> $allowedMethods Allowed HTTP methods
     * @param string $message Error message
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        array $allowedMethods = [],
        string $message = 'Method Not Allowed',
        ?\Throwable $previous = null
    ) {
        $headers = [];
        if (!empty($allowedMethods)) {
            $headers['Allow'] = implode(', ', $allowedMethods);
        }
        parent::__construct(405, $message, $headers, $previous);
    }
}
