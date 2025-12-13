<?php

declare(strict_types=1);

namespace Toporia\Framework\Macro\Contracts;


/**
 * Interface MacroRegistryInterface
 *
 * Contract defining the interface for MacroRegistryInterface
 * implementations in the Macro functionality layer of the Toporia
 * Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Macro\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface MacroRegistryInterface
{
    /**
     * Register a macro for a class or interface.
     *
     * @param string|class-string $target Target class or interface name
     * @param string $name Macro name (method name)
     * @param callable $callback Macro implementation
     * @return void
     */
    public function register(string $target, string $name, callable $callback): void;

    /**
     * Check if macro exists for target.
     *
     * @param string|class-string $target Target class or interface name
     * @param string $name Macro name
     * @return bool True if macro exists
     */
    public function has(string $target, string $name): bool;

    /**
     * Get macro callback for target.
     *
     * @param string|class-string $target Target class or interface name
     * @param string $name Macro name
     * @return callable|null Macro callback or null if not found
     */
    public function get(string $target, string $name): ?callable;

    /**
     * Get all macros for a target.
     *
     * @param string|class-string $target Target class or interface name
     * @return array<string, callable> Array of macro name => callback
     */
    public function getAll(string $target): array;

    /**
     * Remove a macro.
     *
     * @param string|class-string $target Target class or interface name
     * @param string $name Macro name
     * @return void
     */
    public function remove(string $target, string $name): void;

    /**
     * Clear all macros for a target.
     *
     * @param string|class-string $target Target class or interface name
     * @return void
     */
    public function clear(string $target): void;

    /**
     * Clear all registered macros.
     *
     * @return void
     */
    public function clearAll(): void;
}

