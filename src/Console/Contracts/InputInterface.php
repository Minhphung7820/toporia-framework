<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Contracts;


/**
 * Interface InputInterface
 *
 * Contract defining the interface for InputInterface implementations in
 * the CLI command framework layer of the Toporia Framework.
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
interface InputInterface
{
    /**
     * Get argument by name or index
     *
     * @param string|int $key
     * @param mixed $default
     * @return mixed
     */
    public function getArgument(string|int $key, mixed $default = null): mixed;

    /**
     * Get all arguments
     *
     * @return array<string|int, mixed>
     */
    public function getArguments(): array;

    /**
     * Check if argument exists
     *
     * @param string|int $key
     * @return bool
     */
    public function hasArgument(string|int $key): bool;

    /**
     * Get option by name
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getOption(string $name, mixed $default = null): mixed;

    /**
     * Get all options
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array;

    /**
     * Check if option exists
     *
     * @param string $name
     * @return bool
     */
    public function hasOption(string $name): bool;

    /**
     * Check if interactive mode is enabled
     *
     * @return bool
     */
    public function isInteractive(): bool;
}
