<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Exceptions;

/**
 * Class NotFoundHttpException
 *
 * 404 Not Found HTTP Exception.
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
class NotFoundHttpException extends HttpException
{
    public function __construct(string $message = 'Not Found', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, [], $previous);
    }
}
