<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Exception;

use RuntimeException;
use Throwable;


/**
 * Class QueryException
 *
 * Exception class for handling QueryException errors in the Database query
 * building and ORM layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Exception
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
class QueryException extends RuntimeException
{
    /**
     * @param string $message Error message.
     * @param string $query SQL query that failed.
     * @param array $bindings Query parameter bindings.
     * @param Throwable|null $previous Previous exception.
     */
    public function __construct(
        string $message,
        private string $query,
        private array $bindings = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Get the SQL query that failed.
     *
     * @return string
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Get the query bindings.
     *
     * @return array
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }
}
