<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Contracts;

/**
 * Interface CommandLoaderInterface
 *
 * Lazy-loads command classes on demand for optimal performance.
 * Only instantiates commands when they are actually executed.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface CommandLoaderInterface
{
    /**
     * Check if a command exists
     *
     * @param string $name Command name
     * @return bool
     */
    public function has(string $name): bool;

    /**
     * Get command class name by command name
     *
     * @param string $name Command name
     * @return class-string|null
     */
    public function get(string $name): ?string;

    /**
     * Get all available command names
     *
     * @return array<string>
     */
    public function getNames(): array;

    /**
     * Get all command signatures and descriptions (for list command)
     * Returns array of ['name' => 'description']
     *
     * @return array<string, string>
     */
    public function all(): array;
}
