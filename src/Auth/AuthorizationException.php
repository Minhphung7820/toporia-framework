<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth;

use RuntimeException;

/**
 * Class AuthorizationException
 *
 * Thrown when a user is not authorized to perform an action.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class AuthorizationException extends RuntimeException
{
    public function __construct(
        string $message = 'This action is unauthorized.',
        int $code = 403
    ) {
        parent::__construct($message, $code);
    }

    /**
     * Create exception for a specific ability
     *
     * @param string $ability
     * @return self
     */
    public static function forAbility(string $ability): self
    {
        return new self("You are not authorized to perform the '{$ability}' action.");
    }
}
