<?php

declare(strict_types=1);

namespace Toporia\Framework\Console;

use Toporia\Framework\Console\Contracts\InputInterface;
use Toporia\Framework\Console\Contracts\OutputInterface;


/**
 * Abstract Class Command
 *
 * Base console command class providing CLI interface with colored output,
 * user interaction, argument/option parsing, and progress indicators.
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
abstract class Command
{
    /**
     * Exit code for successful command execution.
     */
    public const SUCCESS = 0;

    /**
     * Exit code for failed command execution.
     */
    public const FAILURE = 1;

    /**
     * Exit code for invalid input.
     */
    public const INVALID = 2;

    /**
     * Command signature (name and arguments)
     *
     * Format: "command:name {arg1} {arg2?} {--option} {--option2=}"
     *
     * @var string
     */
    protected string $signature = '';

    /**
     * Command description
     *
     * @var string
     */
    protected string $description = '';

    /**
     * Input interface
     *
     * @var InputInterface|null
     */
    protected ?InputInterface $input = null;

    /**
     * Output interface
     *
     * @var OutputInterface|null
     */
    protected ?OutputInterface $output = null;

    /**
     * Execute the command
     *
     * This is the main method that child classes must implement.
     *
     * @return int Exit code (0 = success, non-zero = error)
     */
    abstract public function handle(): int;

    /**
     * Get command signature
     *
     * @return string
     */
    public function getSignature(): string
    {
        return $this->signature;
    }

    /**
     * Get command name from signature
     *
     * @return string
     */
    public function getName(): string
    {
        return explode(' ', $this->signature)[0];
    }

    /**
     * Get command description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Set input interface
     *
     * @param InputInterface $input
     * @return void
     */
    public function setInput(InputInterface $input): void
    {
        $this->input = $input;
    }

    /**
     * Set output interface
     *
     * @param OutputInterface $output
     * @return void
     */
    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    /**
     * Get argument value
     *
     * @param string|int $key
     * @param mixed $default
     * @return mixed
     */
    protected function argument(string|int $key, mixed $default = null): mixed
    {
        return $this->input?->getArgument($key, $default) ?? $default;
    }

    /**
     * Get option value
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    protected function option(string $name, mixed $default = null): mixed
    {
        return $this->input?->getOption($name, $default) ?? $default;
    }

    /**
     * Check if option exists
     *
     * @param string $name
     * @return bool
     */
    protected function hasOption(string $name): bool
    {
        return $this->input?->hasOption($name) ?? false;
    }

    /**
     * Write output to console
     *
     * @param string $message
     * @return void
     */
    protected function write(string $message): void
    {
        $this->output?->write($message);
    }

    /**
     * Write line to console
     *
     * @param string $message
     * @return void
     */
    protected function writeln(string $message): void
    {
        $this->output?->writeln($message);
    }

    /**
     * Write info message
     *
     * @param string $message
     * @return void
     */
    protected function info(string $message): void
    {
        $this->output?->info($message);
    }

    /**
     * Write error message
     *
     * @param string $message
     * @return void
     */
    protected function error(string $message): void
    {
        $this->output?->error($message);
    }

    /**
     * Write success message
     *
     * @param string $message
     * @return void
     */
    protected function success(string $message): void
    {
        $this->output?->success($message);
    }

    /**
     * Write warning message
     *
     * @param string $message
     * @return void
     */
    protected function warn(string $message): void
    {
        $this->output?->warning($message);
    }

    /**
     * Write line separator
     *
     * @param string $char
     * @param int $length
     * @return void
     */
    protected function line(string $char = '-', int $length = 80): void
    {
        $this->output?->line($char, $length);
    }

    /**
     * Write blank line(s)
     *
     * @param int $count
     * @return void
     */
    protected function newLine(int $count = 1): void
    {
        $this->output?->newLine($count);
    }

    /**
     * Write a table
     *
     * @param array<string> $headers
     * @param array<array<string>> $rows
     * @return void
     */
    protected function table(array $headers, array $rows): void
    {
        $this->output?->table($headers, $rows);
    }

    /**
     * Ask user for confirmation (yes/no)
     *
     * @param string $question
     * @param bool $default
     * @return bool
     */
    protected function confirm(string $question, bool $default = false): bool
    {
        if (!$this->input?->isInteractive()) {
            return $default;
        }

        $suffix = $default ? '[Y/n]' : '[y/N]';
        $this->write("{$question} {$suffix}: ");

        $answer = strtolower(trim(fgets(STDIN) ?: ''));

        if ($answer === '') {
            return $default;
        }

        return in_array($answer, ['y', 'yes']);
    }

    /**
     * Ask user for input
     *
     * @param string $question
     * @param string|null $default
     * @return string
     */
    protected function ask(string $question, ?string $default = null): string
    {
        if (!$this->input?->isInteractive()) {
            return $default ?? '';
        }

        $suffix = $default ? " [{$default}]" : '';
        $this->write("{$question}{$suffix}: ");

        $answer = trim(fgets(STDIN) ?: '');

        return $answer === '' ? ($default ?? '') : $answer;
    }

    /**
     * Ask user to choose from options
     *
     * @param string $question
     * @param array<string> $choices
     * @param string|null $default
     * @return string
     */
    protected function choice(string $question, array $choices, ?string $default = null): string
    {
        if (!$this->input?->isInteractive()) {
            return $default ?? $choices[0];
        }

        $this->writeln($question);
        foreach ($choices as $i => $choice) {
            $this->writeln("  [{$i}] {$choice}");
        }

        $suffix = $default !== null ? " [{$default}]" : '';
        $this->write("Choose{$suffix}: ");

        $answer = trim(fgets(STDIN) ?: '');

        if ($answer === '') {
            return $default ?? $choices[0];
        }

        if (is_numeric($answer) && isset($choices[(int) $answer])) {
            return $choices[(int) $answer];
        }

        return in_array($answer, $choices) ? $answer : ($default ?? $choices[0]);
    }

    /**
     * Get the application base path.
     *
     * @return string
     */
    protected function getBasePath(): string
    {
        // Use APP_BASE_PATH if defined (set by public/index.php or console)
        if (defined('APP_BASE_PATH')) {
            return constant('APP_BASE_PATH');
        }

        // Try to get from container
        if (function_exists('app')) {
            $app = app();
            if (method_exists($app, 'basePath')) {
                return $app->basePath();
            }
        }

        // Fallback to current working directory
        return getcwd() ?: dirname(__DIR__, 5);
    }

    /**
     * Call another console command.
     *
     * Allows commands to invoke other commands programmatically.
     *
     * @param string $command Command name (e.g., 'cache:clear', 'migrate')
     * @param array<string, mixed> $parameters Arguments and options
     * @return int Exit code (0 = success, non-zero = error)
     */
    protected function call(string $command, array $parameters = []): int
    {
        // Get console application from container
        if (function_exists('app')) {
            $foundationApp = app();

            // Get container and resolve Console\Application
            $container = $foundationApp->getContainer();
            $consoleApp = $container->get(Application::class);

            return $consoleApp->call($command, $parameters, $this->output);
        }

        // Fallback: execute via shell
        $args = [$command];
        foreach ($parameters as $key => $value) {
            if (is_int($key)) {
                $args[] = escapeshellarg((string) $value);
            } elseif (str_starts_with($key, '--')) {
                if (is_bool($value)) {
                    if ($value) {
                        $args[] = $key;
                    }
                } else {
                    $args[] = "{$key}=" . escapeshellarg((string) $value);
                }
            }
        }

        $basePath = $this->getBasePath();
        $consolePath = $basePath . '/console';
        $cmd = "php {$consolePath} " . implode(' ', $args);

        passthru($cmd, $exitCode);
        return $exitCode;
    }
}
