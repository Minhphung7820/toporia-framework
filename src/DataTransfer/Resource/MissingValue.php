<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Resource;

/**
 * Class MissingValue
 *
 * Represents a missing/undefined value in resources.
 * Used by conditional attributes (when, whenLoaded) to indicate
 * that a value should be omitted from the response.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DataTransfer\Resource
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class MissingValue
{
    /**
     * Check if value is missing.
     *
     * @param mixed $value
     * @return bool
     */
    public static function isMissing(mixed $value): bool
    {
        return $value instanceof self;
    }
}
