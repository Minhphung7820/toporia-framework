<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;

/**
 * Test Command
 *
 * Run PHPUnit tests.
 *
 * Usage:
 *   php console test
 *   php console test --filter=test_name
 *   php console test --testsuite=Unit
 *   php console test --coverage
 *   php console test --parallel
 *
 * Performance:
 * - O(1) command execution
 * - Fast test discovery
 * - Efficient process management
 *
 * Clean Architecture:
 * - Single Responsibility: Only runs tests
 * - Open/Closed: Extensible via options
 */
/**
 * Class TestCommand
 *
 * Run application tests.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class TestCommand extends Command
{
    protected string $signature = 'test {--filter=} {--testsuite=} {--coverage} {--coverage-html} {--parallel} {--stop-on-failure} {--verbose}';

    protected string $description = 'Run PHPUnit tests';

    /**
     * Execute the command.
     *
     * Performance: O(1) - Command execution
     */
    public function handle(): int
    {
        $phpunitPath = $this->getPhpunitPath();

        if (!file_exists($phpunitPath)) {
            $this->error('PHPUnit not found. Run: composer install --dev');
            return 1;
        }

        $arguments = $this->buildArguments();

        $this->info('Running PHPUnit tests...');
        $this->newLine();

        // Build command
        $command = array_merge(['php', $phpunitPath], $arguments);
        $commandString = implode(' ', array_map('escapeshellarg', $command));

        // Execute PHPUnit
        passthru($commandString, $exitCode);

        return $exitCode;
    }

    /**
     * Get PHPUnit path.
     *
     * Performance: O(1)
     */
    private function getPhpunitPath(): string
    {
        $vendorPath = getcwd() . '/vendor/bin/phpunit';

        if (file_exists($vendorPath)) {
            return $vendorPath;
        }

        // Fallback: try to find in PATH
        return 'phpunit';
    }

    /**
     * Build PHPUnit arguments from command options.
     *
     * Performance: O(N) where N = number of options
     */
    private function buildArguments(): array
    {
        $arguments = [];

        // Filter
        if ($filter = $this->option('filter')) {
            $arguments[] = '--filter';
            $arguments[] = $filter;
        }

        // Test suite
        if ($testsuite = $this->option('testsuite')) {
            $arguments[] = '--testsuite';
            $arguments[] = $testsuite;
        }

        // Coverage
        if ($this->hasOption('coverage')) {
            $arguments[] = '--coverage-text';
        }

        if ($this->hasOption('coverage-html')) {
            $arguments[] = '--coverage-html';
            $arguments[] = 'coverage';
        }

        // Stop on failure
        if ($this->hasOption('stop-on-failure')) {
            $arguments[] = '--stop-on-failure';
        }

        // Verbose
        if ($this->hasOption('verbose')) {
            $arguments[] = '--verbose';
        }

        // Parallel (requires PHPUnit 10+)
        if ($this->hasOption('parallel')) {
            $arguments[] = '--process-isolation';
        }

        return $arguments;
    }
}

