<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Exceptions;

/**
 * Class AccessDeniedHttpException
 *
 * 403 Forbidden HTTP Exception.
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
class AccessDeniedHttpException extends HttpException
{
    public function __construct(string $message = 'Forbidden', ?\Throwable $previous = null)
    {
        parent::__construct(403, $message, [], $previous);
    }
}
