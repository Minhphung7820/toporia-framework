<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Exceptions;

use RuntimeException;

/**
 * Class ImmutableException
 *
 * Exception thrown when attempting to modify an immutable object.
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
class ImmutableException extends RuntimeException
{
}
