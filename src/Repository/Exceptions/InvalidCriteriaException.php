<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Exceptions;

/**
 * Class InvalidCriteriaException
 *
 * Exception thrown when invalid criteria is provided.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Repository\Exceptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class InvalidCriteriaException extends RepositoryException
{
    public function __construct(string $message = 'Invalid criteria provided')
    {
        parent::__construct($message);
    }
}
