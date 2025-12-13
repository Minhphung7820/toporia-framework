<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Exceptions;

/**
 * Class UnauthorizedHttpException
 *
 * 401 Unauthorized HTTP Exception.
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
class UnauthorizedHttpException extends HttpException
{
    public function __construct(
        string $challenge = '',
        string $message = 'Unauthorized',
        ?\Throwable $previous = null
    ) {
        $headers = [];
        if ($challenge !== '') {
            $headers['WWW-Authenticate'] = $challenge;
        }
        parent::__construct(401, $message, $headers, $previous);
    }
}
