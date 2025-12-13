<?php

declare(strict_types=1);

namespace Toporia\Framework\Encryption\Exceptions;

use RuntimeException;

/**
 * Class EncryptException
 *
 * Thrown when encryption fails due to OpenSSL errors or other
 * encryption-related issues.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Encryption\Exceptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class EncryptException extends RuntimeException
{
}
