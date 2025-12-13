<?php

declare(strict_types=1);

namespace Toporia\Framework\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;


/**
 * Class NotFoundException
 *
 * Exception class for handling NotFoundException errors in the Dependency
 * Injection container layer of the Toporia Framework.
 *
 * Implements PSR-11 NotFoundExceptionInterface for interoperability.
 * Thrown when a requested service/entry is not found in the container.
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
class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
