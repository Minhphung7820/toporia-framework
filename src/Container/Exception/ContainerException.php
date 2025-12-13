<?php

declare(strict_types=1);

namespace Toporia\Framework\Container\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;


/**
 * Class ContainerException
 *
 * Exception class for handling ContainerException errors in the Dependency
 * Injection container layer of the Toporia Framework.
 *
 * Implements PSR-11 ContainerExceptionInterface for interoperability.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Container\Exception
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
