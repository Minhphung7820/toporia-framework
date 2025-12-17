<?php

declare(strict_types=1);

namespace Toporia\Framework\Console;

use Closure;
use Toporia\Framework\Console\ClosureCommand;
use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Class TerminalCommandRegistrar
 *
 * Manages registration of closure-based terminal commands.
 * Acts as the backend service for Terminal accessor facade.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console
 * @since       2025-01-17
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class TerminalCommandRegistrar
{
    /**
     * Registered closure commands
     *
     * @var array<string, ClosureCommand>
     */
    private array $commands = [];

    /**
     * Constructor
     *
     * @param ContainerInterface $container
     */
    public function __construct(
        private readonly ContainerInterface $container
    ) {
    }

    /**
     * Register a closure-based command
     *
     * @param string $signature Command signature (e.g., "mail:send {user} {--queue=}")
     * @param Closure $callback The closure to execute
     * @return ClosureCommand
     */
    public function command(string $signature, Closure $callback): ClosureCommand
    {
        $command = new ClosureCommand($signature, $callback, $this->container);

        // Extract command name from signature
        $name = explode(' ', $signature)[0];

        // Store for later registration
        $this->commands[$name] = $command;

        return $command;
    }

    /**
     * Get all registered closure commands
     *
     * @return array<string, ClosureCommand>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Get command by name
     *
     * @param string $name
     * @return ClosureCommand|null
     */
    public function getCommand(string $name): ?ClosureCommand
    {
        return $this->commands[$name] ?? null;
    }

    /**
     * Check if command exists
     *
     * @param string $name
     * @return bool
     */
    public function hasCommand(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * Get command map for LazyCommandLoader
     *
     * Returns array in format: [commandName => commandClass]
     * Since ClosureCommand instances are already created, we return them directly.
     *
     * @return array<string, ClosureCommand>
     */
    public function getCommandMap(): array
    {
        return $this->commands;
    }
}
