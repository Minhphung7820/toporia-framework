<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query;

/**
 * SQL Expression Wrapper
 *
 * Marks a string as raw SQL that should not be quoted/escaped.
 * Following the Marker Pattern for type distinction.
 *
 * Use case:
 * - Raw SQL expressions in selectRaw()
 * - Prevents Grammar from wrapping with backticks/quotes
 *
 * SOLID Principles:
 * - Single Responsibility: Only marks raw SQL
 * - Open/Closed: Extends without modifying Grammar
 * - Liskov Substitution: Can be used anywhere string is expected
 *
 * Performance: O(1) - lightweight wrapper, no overhead
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Query
 * @since       2025-01-23
 */
final class Expression
{
    /**
     * Raw SQL expression.
     *
     * @var string
     */
    private string $value;

    /**
     * Create new raw expression.
     *
     * @param string $value Raw SQL
     */
    public function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Get the raw SQL value.
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Convert to string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
