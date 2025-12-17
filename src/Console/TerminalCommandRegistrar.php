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
     * Command metadata for lazy loading
     *
     * @var array<string, array{signature: string, callback: Closure, description: string}>
     */
    private array $commandMetadata = [];

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
     * Register a closure-based command (LAZY - stores metadata only)
     *
     * Returns a fluent builder for chaining ->describe()
     *
     * @param string $signature Command signature (e.g., "mail:send {user} {--queue=}")
     * @param Closure $callback The closure to execute
     * @return TerminalCommandBuilder Fluent builder for method chaining
     */
    public function command(string $signature, Closure $callback): TerminalCommandBuilder
    {
        // Extract command name from signature
        $name = explode(' ', $signature)[0];

        // Store metadata only (no instantiation yet)
        $this->commandMetadata[$name] = [
            'signature' => $signature,
            'callback' => $callback,
            'description' => '', // Will be set via describe()
        ];

        // Return builder for fluent API
        return new TerminalCommandBuilder($this, $name);
    }

    /**
     * Set command description (called by builder)
     *
     * @param string $name Command name
     * @param string $description
     * @return void
     * @internal
     */
    public function setDescription(string $name, string $description): void
    {
        if (isset($this->commandMetadata[$name])) {
            $this->commandMetadata[$name]['description'] = $description;
        }
    }

    /**
     * Get command metadata
     *
     * @param string $name
     * @return array{signature: string, callback: Closure, description: string}|null
     */
    public function getMetadata(string $name): ?array
    {
        return $this->commandMetadata[$name] ?? null;
    }

    /**
     * Get all command metadata
     *
     * @return array<string, array{signature: string, callback: Closure, description: string}>
     */
    public function getAllMetadata(): array
    {
        return $this->commandMetadata;
    }

    /**
     * Create ClosureCommand instance from metadata (LAZY instantiation)
     *
     * @param string $name Command name
     * @return ClosureCommand|null
     */
    public function createCommand(string $name): ?ClosureCommand
    {
        $metadata = $this->commandMetadata[$name] ?? null;

        if ($metadata === null) {
            return null;
        }

        $command = new ClosureCommand(
            $metadata['signature'],
            $metadata['callback'],
            $this->container
        );

        // Set description if provided
        if ($metadata['description'] !== '') {
            $command->setDescription($metadata['description']);
        }

        return $command;
    }

    /**
     * Check if command exists
     *
     * @param string $name
     * @return bool
     */
    public function hasCommand(string $name): bool
    {
        return isset($this->commandMetadata[$name]);
    }

    /**
     * Get command map for LazyCommandLoader
     *
     * Returns metadata for lazy loading instead of eager instances.
     *
     * @return array<string, array{signature: string, callback: Closure, description: string}>
     */
    public function getCommandMap(): array
    {
        return $this->commandMetadata;
    }
}
