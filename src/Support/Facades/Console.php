<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Facades;

use Toporia\Framework\Console\Application;
use Toporia\Framework\Console\Contracts\OutputInterface;

/**
 * Console Facade
 *
 * Provides static access to Toporia's console commands.
 * Allows calling console commands programmatically from PHP code.
 *
 * Usage:
 *   Console::call('migrate');
 *   Console::call('cache:clear', ['--force' => true]);
 *   Console::call('user:create', ['name' => 'John', '--admin' => true]);
 *
 *   $output = Console::callSilent('route:list');
 *
 * @method static int call(string $commandName, array $parameters = [], ?OutputInterface $output = null)
 * @method static string callSilent(string $commandName, array $parameters = [])
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Facades
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class Console
{
    /**
     * @var Application|null Cached application instance
     */
    private static ?Application $application = null;

    /**
     * Get the console application instance
     *
     * @return Application
     */
    private static function getApplication(): Application
    {
        if (self::$application === null) {
            // Get from global container if available
            if (function_exists('app')) {
                self::$application = app(Application::class);
            } else {
                throw new \RuntimeException(
                    'Console application not available. Make sure the application is bootstrapped.'
                );
            }
        }

        return self::$application;
    }

    /**
     * Set the console application instance (for testing)
     *
     * @param Application $application
     * @return void
     */
    public static function setApplication(Application $application): void
    {
        self::$application = $application;
    }

    /**
     * Call a console command programmatically
     *
     * @param string $commandName Command name
     * @param array<string, mixed> $parameters Arguments and options
     * @param OutputInterface|null $output Custom output
     * @return int Exit code (0 = success)
     */
    public static function call(string $commandName, array $parameters = [], ?OutputInterface $output = null): int
    {
        return self::getApplication()->call($commandName, $parameters, $output);
    }

    /**
     * Call a console command and capture output as string
     *
     * @param string $commandName Command name
     * @param array<string, mixed> $parameters Arguments and options
     * @return string Command output
     */
    public static function callSilent(string $commandName, array $parameters = []): string
    {
        return self::getApplication()->callSilent($commandName, $parameters);
    }

    /**
     * Clear the cached application instance (for testing)
     *
     * @return void
     */
    public static function clearResolvedInstance(): void
    {
        self::$application = null;
    }

    /**
     * Forward static calls to the application instance
     *
     * @param string $method
     * @param array<mixed> $arguments
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::getApplication()->$method(...$arguments);
    }
}
