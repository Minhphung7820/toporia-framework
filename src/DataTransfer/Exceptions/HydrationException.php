<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Class HydrationException
 *
 * Exception thrown when object hydration fails.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DataTransfer\Exceptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class HydrationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $className = null,
        public readonly ?string $propertyName = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Create exception for missing required property.
     */
    public static function missingProperty(string $className, string $propertyName): self
    {
        return new self(
            "Missing required property '{$propertyName}' for class '{$className}'",
            $className,
            $propertyName
        );
    }

    /**
     * Create exception for type mismatch.
     */
    public static function typeMismatch(string $className, string $propertyName, string $expected, string $actual): self
    {
        return new self(
            "Type mismatch for property '{$propertyName}' in '{$className}': expected {$expected}, got {$actual}",
            $className,
            $propertyName
        );
    }

    /**
     * Create exception for non-instantiable class.
     */
    public static function notInstantiable(string $className): self
    {
        return new self("Class '{$className}' is not instantiable", $className);
    }
}
