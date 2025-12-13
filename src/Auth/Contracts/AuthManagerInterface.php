<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Contracts;


/**
 * Interface AuthManagerInterface
 *
 * Contract defining the interface for AuthManagerInterface implementations
 * in the Authentication and authorization layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface AuthManagerInterface
{
    /**
     * Get a guard instance by name.
     *
     * @param string|null $name Guard name or null for default.
     * @return GuardInterface Guard instance.
     * @throws \InvalidArgumentException If guard not found.
     */
    public function guard(?string $name = null): GuardInterface;

    /**
     * Set the default guard name.
     *
     * @param string $name Guard name.
     * @return void
     */
    public function setDefaultGuard(string $name): void;

    /**
     * Get the default guard name.
     *
     * @return string Default guard name.
     */
    public function getDefaultGuard(): string;

    /**
     * Check if a guard exists.
     *
     * @param string $name Guard name.
     * @return bool True if guard exists.
     */
    public function hasGuard(string $name): bool;

    /**
     * Extend the manager with a custom guard.
     *
     * @param string $name Guard name.
     * @param callable $callback Guard factory callback.
     * @return void
     */
    public function extend(string $name, callable $callback): void;
}
