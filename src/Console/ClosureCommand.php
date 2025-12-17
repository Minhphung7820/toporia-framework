<?php

declare(strict_types=1);

namespace Toporia\Framework\Console;

use Closure;
use Toporia\Framework\Console\Command;
use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Class ClosureCommand
 *
 * Wraps a closure as a console command with full dependency injection support.
 * Enables lightweight command definition in routes/terminal.php without creating
 * full command classes.
 *
 * Features:
 * - Automatic dependency injection for closure parameters
 * - Access to all Command methods via $this binding
 * - Support for arguments and options
 * - Fluent API for description and scheduling
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
final class ClosureCommand extends Command
{
    /**
     * The closure to execute
     *
     * @var Closure
     */
    private Closure $callback;

    /**
     * The service container
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * Constructor
     *
     * @param string $signature Command signature
     * @param Closure $callback The closure to execute
     * @param ContainerInterface $container Service container for DI
     */
    public function __construct(
        string $signature,
        Closure $callback,
        ContainerInterface $container
    ) {
        $this->signature = $signature;
        $this->callback = $callback;
        $this->container = $container;
    }

    /**
     * Set command description
     *
     * @param string $description
     * @return self
     */
    public function describe(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Execute the command
     *
     * The closure is bound to $this, giving it access to all Command methods:
     * - $this->info(), $this->error(), $this->warn(), $this->line()
     * - $this->ask(), $this->confirm(), $this->choice()
     * - $this->table(), $this->progressBar()
     * - $this->argument(), $this->option()
     * - $this->call() - call other commands
     *
     * @return int Exit code
     */
    public function handle(): int
    {
        // Bind closure to $this so it can access Command methods
        $callback = $this->callback->bindTo($this, $this);

        // Resolve closure dependencies from container
        $result = $this->container->call($callback, $this->getClosureParameters());

        // Return exit code (default to SUCCESS if null/void returned)
        return is_int($result) ? $result : self::SUCCESS;
    }

    /**
     * Get parameters to pass to closure
     *
     * Extracts argument values to pass as closure parameters.
     * The container will resolve type-hinted dependencies automatically.
     *
     * @return array<string, mixed>
     */
    private function getClosureParameters(): array
    {
        $parameters = [];

        // Get all arguments from command signature
        preg_match_all('/{([^}]+)}/', $this->signature, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $arg) {
                // Remove optional marker and default values
                $argName = trim(str_replace(['?', '--'], '', explode('=', $arg)[0]));

                // Skip options (start with --)
                if (str_contains($arg, '--')) {
                    continue;
                }

                // Get argument value
                if ($this->input) {
                    $parameters[$argName] = $this->argument($argName);
                }
            }
        }

        return $parameters;
    }
}
