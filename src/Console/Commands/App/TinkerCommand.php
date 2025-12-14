<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\App;

use Toporia\Framework\Console\Command;

/**
 * Class TinkerCommand
 *
 * Interactive REPL (Read-Eval-Print Loop) for interacting with your application.
 * Allows executing PHP code directly within the application context.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\App
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class TinkerCommand extends Command
{
    protected string $signature = 'tinker {include?* : Files to include before starting}';

    protected string $description = 'Interact with your application';

    public function handle(): int
    {
        // Include any specified files
        $includes = $this->argument('include') ?: [];

        foreach ($includes as $include) {
            if (file_exists($include)) {
                require $include;
            }
        }

        $this->info('Toporia REPL');
        $this->info('Type "exit" to quit.');
        $this->newLine();

        // Start interactive REPL
        $this->startRepl();

        return 0;
    }

    private function startRepl(): void
    {
        $basePath = $this->getBasePath();

        while (true) {
            $line = $this->prompt('>>> ');

            if ($line === null || trim($line) === 'exit' || trim($line) === 'quit') {
                $this->info('Goodbye!');
                break;
            }

            if (empty(trim($line))) {
                continue;
            }

            try {
                // Handle multi-line input
                while (!$this->isCompleteLine($line)) {
                    $continuation = $this->prompt('... ');
                    if ($continuation === null) {
                        break;
                    }
                    $line .= "\n" . $continuation;
                }

                // Execute the code
                $result = $this->execute($line);

                if ($result !== null) {
                    $this->printResult($result);
                }

            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
        }
    }

    private function prompt(string $prompt): ?string
    {
        echo $prompt;

        $line = fgets(STDIN);

        if ($line === false) {
            return null;
        }

        return rtrim($line, "\n\r");
    }

    private function isCompleteLine(string $code): bool
    {
        // Simple check - count braces, brackets, parentheses
        $open = substr_count($code, '{') + substr_count($code, '[') + substr_count($code, '(');
        $close = substr_count($code, '}') + substr_count($code, ']') + substr_count($code, ')');

        return $open === $close;
    }

    private function execute(string $code): mixed
    {
        // Add return statement if needed
        $code = trim($code);

        if (!str_ends_with($code, ';')) {
            $code .= ';';
        }

        // If it's an expression, add return
        if (!str_starts_with($code, 'return ') &&
            !str_contains($code, '=') &&
            !str_starts_with($code, 'if ') &&
            !str_starts_with($code, 'foreach ') &&
            !str_starts_with($code, 'for ') &&
            !str_starts_with($code, 'while ') &&
            !str_starts_with($code, 'echo ') &&
            !str_starts_with($code, 'print ')) {
            $code = 'return ' . $code;
        }

        return eval($code);
    }

    private function printResult(mixed $result): void
    {
        if (is_string($result)) {
            $this->writeln('"' . $result . '"');
        } elseif (is_bool($result)) {
            $this->writeln($result ? 'true' : 'false');
        } elseif (is_null($result)) {
            $this->writeln('null');
        } elseif (is_array($result) || is_object($result)) {
            $this->writeln(print_r($result, true));
        } else {
            $this->writeln((string) $result);
        }
    }

        return getcwd() ?: dirname(__DIR__, 5);
    }
}
