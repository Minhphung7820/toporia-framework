<?php

declare(strict_types=1);

namespace Toporia\Framework\Security\Contracts;


/**
 * Interface CsrfTokenManagerInterface
 *
 * Contract defining the interface for CsrfTokenManagerInterface
 * implementations in the Security features layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Security\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface CsrfTokenManagerInterface
{
    /**
     * Generate a new CSRF token
     *
     * @param string $key Token identifier (e.g., form name)
     * @return string The generated token
     */
    public function generate(string $key = '_token'): string;

    /**
     * Validate a CSRF token
     *
     * @param string $token The token to validate
     * @param string $key Token identifier
     * @return bool True if valid, false otherwise
     */
    public function validate(string $token, string $key = '_token'): bool;

    /**
     * Regenerate the CSRF token
     *
     * @param string $key Token identifier
     * @return string The new token
     */
    public function regenerate(string $key = '_token'): string;

    /**
     * Remove a CSRF token
     *
     * @param string $key Token identifier
     * @return void
     */
    public function remove(string $key = '_token'): void;

    /**
     * Get the current token without generating a new one
     *
     * @param string $key Token identifier
     * @return string|null The token or null if not exists
     */
    public function getToken(string $key = '_token'): ?string;
}
