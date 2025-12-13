<?php

declare(strict_types=1);

namespace Toporia\Framework\Application\Exception;

/**
 * Query Validation Exception
 *
 * Thrown when query validation fails.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Application\Exception
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class QueryValidationException extends ApplicationException
{
    /**
     * @param array<string, string> $errors Validation errors
     * @param string $message Exception message
     */
    public function __construct(
        public readonly array $errors = [],
        string $message = 'Query validation failed'
    ) {
        parent::__construct($message);
    }
}

