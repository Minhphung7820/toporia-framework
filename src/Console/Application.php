<?php

declare(strict_types=1);

namespace Toporia\Framework\Console;

use Toporia\Framework\Console\Contracts\{CommandLoaderInterface, InputInterface, OutputInterface};
use Toporia\Framework\Console\Input;
use Toporia\Framework\Console\Output;
use Toporia\Framework\Container\Contracts\ContainerInterface;

/**
 * Class Application
 *
 * Console application with lazy command loading for optimal performance.
 * Uses LazyCommandLoader to defer command instantiation until execution.
 *
 * Performance Improvements:
 * - Commands instantiated only when executed (lazy loading)
 * - O(1) command lookup via CommandLoader
 * - Minimal memory footprint (~10-20 MB less for 80+ commands)
 * - Faster boot time (~50-100ms improvement)
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Application
{
  private InputInterface $input;
  private OutputInterface $output;
  private ?CommandLoaderInterface $loader = null;

  public function __construct(
    private readonly ContainerInterface $container
  ) {
    $this->output = new Output();

    // Initialize with empty lazy loader
    $this->loader = new LazyCommandLoader($this->container);
  }

  /**
   * Set command loader (for dependency injection)
   *
   * @param CommandLoaderInterface $loader
   * @return void
   */
  public function setLoader(CommandLoaderInterface $loader): void
  {
    $this->loader = $loader;
  }

  /**
   * Get command loader
   *
   * @return CommandLoaderInterface
   */
  public function getLoader(): CommandLoaderInterface
  {
    return $this->loader;
  }

  /**
   * Register a command class (LAZY - no instantiation)
   *
   * @param class-string<Command> $commandClass
   * @deprecated Use setLoader() with pre-configured LazyCommandLoader instead
   */
  public function register(string $commandClass): void
  {
    // PERFORMANCE: No longer instantiates command to get name
    // Instead, requires command name to be provided or use registerMany with map

    // Fallback: Instantiate to get name (for backward compatibility)
    // This is SLOW but maintains compatibility with old code
    /** @var Command $instance */
    $instance = $this->container->get($commandClass);
    $name = $instance->getName();

    if ($this->loader instanceof LazyCommandLoader) {
      $this->loader->register($name, $commandClass);
    }
  }

  /**
   * Register multiple command classes (LAZY)
   *
   * @param array<class-string<Command>> $commandClasses
   * @return void
   * @deprecated Use setLoader() with pre-configured LazyCommandLoader instead
   */
  public function registerMany(array $commandClasses): void
  {
    foreach ($commandClasses as $commandClass) {
      $this->register($commandClass);
    }
  }

  /**
   * Register commands with explicit names (LAZY - best performance)
   *
   * @param array<string, class-string<Command>> $commands ['command:name' => 'ClassName']
   * @return void
   */
  public function registerCommandMap(array $commands): void
  {
    if ($this->loader instanceof LazyCommandLoader) {
      $this->loader->registerMany($commands);
    }
  }

  /**
   * Run the console application.
   *
   * @param array<int, string> $argv
   */
  public function run(array $argv): int
  {
    // Parse input
    $this->input = Input::fromArgv($argv);

    // Get command name
    $commandName = $argv[1] ?? 'list';

    // Handle built-in commands
    if ($commandName === 'list') {
      return $this->listCommands();
    }

    // Find and execute command (LAZY - only loads when executed)
    if (!$this->loader->has($commandName)) {
      $this->output->error("Command not found: {$commandName}");
      $this->output->writeln("Run 'list' to see available commands.");
      return 1;
    }

    return $this->executeCommand($commandName);
  }

  /**
   * Call a console command programmatically (programmatically)
   *
   * Usage:
   *   $exitCode = $console->call('migrate');
   *   $exitCode = $console->call('cache:clear', ['--force' => true]);
   *   $exitCode = $console->call('user:create', ['name' => 'John', '--admin' => true]);
   *
   * @param string $commandName Command name (e.g., 'migrate', 'cache:clear')
   * @param array<string, mixed> $parameters Arguments and options
   * @param OutputInterface|null $output Custom output (null = use default)
   * @return int Exit code (0 = success, non-zero = error)
   */
  public function call(string $commandName, array $parameters = [], ?OutputInterface $output = null): int
  {
    // Check if command exists
    if (!$this->loader->has($commandName)) {
      throw new \InvalidArgumentException("Command not found: {$commandName}");
    }

    // Build argv array from parameters
    $argv = [$_SERVER['PHP_SELF'] ?? 'console', $commandName];

    foreach ($parameters as $key => $value) {
      if (is_int($key)) {
        // Positional argument
        $argv[] = (string) $value;
      } elseif (str_starts_with($key, '--')) {
        // Long option
        if (is_bool($value)) {
          if ($value) {
            $argv[] = $key;
          }
        } else {
          $argv[] = "{$key}={$value}";
        }
      } elseif (str_starts_with($key, '-')) {
        // Short option
        $argv[] = $key;
        if (!is_bool($value)) {
          $argv[] = (string) $value;
        }
      } else {
        // Named argument
        $argv[] = (string) $value;
      }
    }

    // Parse input from constructed argv
    $this->input = Input::fromArgv($argv);

    // Set output (use custom or default)
    $originalOutput = $this->output;
    if ($output !== null) {
      $this->output = $output;
    }

    try {
      // Execute command
      $exitCode = $this->executeCommand($commandName);
    } finally {
      // Restore original output
      $this->output = $originalOutput;
    }

    return $exitCode;
  }

  /**
   * Call a console command and get the output as string
   *
   * Usage:
   *   $output = $console->callSilent('route:list');
   *   echo $output; // Shows the route list
   *
   * @param string $commandName Command name
   * @param array<string, mixed> $parameters Arguments and options
   * @return string Command output
   */
  public function callSilent(string $commandName, array $parameters = []): string
  {
    // Create a buffer output to capture the output
    $buffer = '';

    $output = new class($buffer) implements OutputInterface {
      private string $buffer = '';

      public function __construct(string &$buffer)
      {
        $this->buffer = &$buffer;
      }

      public function write(string $message, bool $newline = false): void
      {
        $this->buffer .= $message;
        if ($newline) {
          $this->buffer .= PHP_EOL;
        }
      }

      public function writeln(string $message = ''): void
      {
        $this->write($message, true);
      }

      public function newLine(int $count = 1): void
      {
        $this->buffer .= str_repeat(PHP_EOL, $count);
      }

      public function info(string $message): void
      {
        $this->writeln("[INFO] {$message}");
      }

      public function success(string $message): void
      {
        $this->writeln("[SUCCESS] {$message}");
      }

      public function warning(string $message): void
      {
        $this->writeln("[WARNING] {$message}");
      }

      public function error(string $message): void
      {
        $this->writeln("[ERROR] {$message}");
      }

      public function table(array $headers, array $rows): void
      {
        // Simple table implementation for buffer
        $this->writeln(implode(' | ', $headers));
        foreach ($rows as $row) {
          $this->writeln(implode(' | ', $row));
        }
      }

      public function line(string $char = '-', int $length = 80): void
      {
        $this->writeln(str_repeat($char, $length));
      }

      public function getBuffer(): string
      {
        return $this->buffer;
      }
    };

    // Call command with buffer output
    $this->call($commandName, $parameters, $output);

    // Return captured output
    return $output->getBuffer();
  }

  /**
   * Execute a registered command (LAZY - instantiates here)
   *
   * @param string $commandName
   * @return int
   */
  private function executeCommand(string $commandName): int
  {
    try {
      // PERFORMANCE: Command instantiated ONLY when executed (not at boot time)
      // Use loader's resolveCommand to support both regular commands and closure commands
      /** @var Command $command */
      $command = $this->loader->resolveCommand($commandName);

      // Parse signature to get argument names and map positional arguments
      $this->mapArgumentsFromSignature($command->getSignature());

      // Inject Input/Output
      $command->setInput($this->input);
      $command->setOutput($this->output);

      // Execute command
      return $command->handle();
    } catch (\Throwable $e) {
      $this->renderException($e);
      return 1;
    }
  }

  /**
   * Parse command signature and map positional arguments to their names
   *
   * @param string $signature
   * @return void
   */
  private function mapArgumentsFromSignature(string $signature): void
  {
    // Extract argument definitions from signature: {name} {name?} {name=default}
    preg_match_all('/\{([^-][^}]*)\}/', $signature, $matches);

    if (empty($matches[1])) {
      return;
    }

    $arguments = $this->input->getArguments();
    $mappedArguments = [];
    $positionalIndex = 0;

    foreach ($matches[1] as $definition) {
      // Skip options (they start with --)
      if (str_starts_with($definition, '-')) {
        continue;
      }

      // Parse argument: name, name?, name=default, or name : description
      $parts = explode(':', $definition, 2);
      $argDef = trim($parts[0]);

      // Handle optional marker and default value
      $isOptional = str_ends_with($argDef, '?');
      $argDef = rtrim($argDef, '?');

      // Handle default value
      $default = null;
      if (str_contains($argDef, '=')) {
        [$argDef, $default] = explode('=', $argDef, 2);
      }

      $argName = trim($argDef);

      // Map positional argument to named argument
      if (isset($arguments[$positionalIndex])) {
        $mappedArguments[$argName] = $arguments[$positionalIndex];
        $positionalIndex++;
      } elseif ($default !== null) {
        $mappedArguments[$argName] = $default;
      }
    }

    // Update input with mapped arguments
    if ($this->input instanceof Input) {
      $this->input->setArguments($mappedArguments);
    }
  }

  /**
   * List all registered commands (LAZY - uses cached descriptions)
   *
   * @return int
   */
  private function listCommands(): int
  {
    $this->output->writeln("Available commands:");
    $this->output->newLine();

    // Get all commands with descriptions (LAZY - uses reflection, not instantiation)
    $commands = $this->loader->all();

    if (empty($commands)) {
      $this->output->warning("No commands registered.");
      return 0;
    }

    // Prepare table data
    $headers = ['Command', 'Description'];
    $rows = [];

    foreach ($commands as $name => $description) {
      $rows[] = [$name, $description];
    }

    // Sort by command name
    usort($rows, fn($a, $b) => strcmp($a[0], $b[0]));

    $this->output->table($headers, $rows);

    $this->output->newLine();
    $this->output->info("Run 'php console [command] --help' for more information.");

    return 0;
  }

  /**
   * Set custom output (for testing)
   *
   * @param OutputInterface $output
   * @return void
   */
  public function setOutput(OutputInterface $output): void
  {
    $this->output = $output;
  }

  /**
   * Render an exception with beautiful formatting.
   *
   * @param \Throwable $e
   * @return void
   */
  private function renderException(\Throwable $e): void
  {
    $renderer = new ExceptionRenderer();
    $renderer->render($e);
  }
}
