<?php

declare(strict_types=1);

namespace Toporia\Framework\Console;

use Toporia\Framework\Console\Contracts\InputInterface;

/**
 * Class Input
 *
 * Parses and provides access to command-line arguments and options.
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
final class Input implements InputInterface
{
    /** @var array<string|int, mixed> */
    private array $arguments = [];

    /** @var array<string, mixed> */
    private array $options = [];

    private bool $interactive = true;

    /**
     * Create input from argv
     *
     * @param array<int, string> $argv
     * @return self
     */
    public static function fromArgv(array $argv): self
    {
        $input = new self();
        $input->parse($argv);
        return $input;
    }

    /**
     * Parse argv into arguments and options
     *
     * Supports:
     * - Named arguments: key=value
     * - Options: --option or --option=value
     * - Flags: --flag (boolean true)
     * - Short options: -v (boolean true)
     * - Positional arguments: anything else
     *
     * @param array<int, string> $argv
     * @return void
     */
    private function parse(array $argv): void
    {
        $positionalIndex = 0;

        // Skip script name and command name
        $args = array_slice($argv, 2);

        foreach ($args as $arg) {
            // Long option with value: --option=value
            if (preg_match('/^--([^=]+)=(.+)$/', $arg, $matches)) {
                $this->options[$matches[1]] = $this->castValue($matches[2]);
                continue;
            }

            // Long option or flag: --option
            if (str_starts_with($arg, '--')) {
                $name = substr($arg, 2);

                // Special flags
                if ($name === 'no-interaction' || $name === 'no-ansi') {
                    $this->interactive = false;
                    $this->options[$name] = true;
                } else {
                    $this->options[$name] = true;
                }
                continue;
            }

            // Short option: -v, -vvv
            if (str_starts_with($arg, '-') && strlen($arg) > 1) {
                $chars = str_split(substr($arg, 1));
                foreach ($chars as $char) {
                    // Count verbosity levels (-v, -vv, -vvv)
                    if ($char === 'v') {
                        $this->options['verbosity'] = ($this->options['verbosity'] ?? 0) + 1;
                    } else {
                        $this->options[$char] = true;
                    }
                }
                continue;
            }

            // Named argument: key=value
            if (str_contains($arg, '=')) {
                [$key, $value] = explode('=', $arg, 2);
                $this->arguments[$key] = $this->castValue($value);
                continue;
            }

            // Positional argument
            $this->arguments[$positionalIndex++] = $this->castValue($arg);
        }
    }

    /**
     * Cast string value to appropriate type
     *
     * @param string $value
     * @return mixed
     */
    private function castValue(string $value): mixed
    {
        // Boolean
        if (in_array(strtolower($value), ['true', 'false'])) {
            return strtolower($value) === 'true';
        }

        // Integer
        if (ctype_digit($value)) {
            return (int) $value;
        }

        // Float
        if (is_numeric($value)) {
            return (float) $value;
        }

        return $value;
    }

    public function getArgument(string|int $key, mixed $default = null): mixed
    {
        return $this->arguments[$key] ?? $default;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function hasArgument(string|int $key): bool
    {
        return isset($this->arguments[$key]);
    }

    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    /**
     * Set arguments manually (for testing)
     *
     * @param array<string|int, mixed> $arguments
     * @return void
     */
    public function setArguments(array $arguments): void
    {
        $this->arguments = $arguments;
    }

    /**
     * Set options manually (for testing)
     *
     * @param array<string, mixed> $options
     * @return void
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }
}
