<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation;

/**
 * Class LoadEnvironmentVariables
 *
 * Loads environment variables from .env file and merges with system environment.
 * This should be called early in the bootstrap process, before any configuration
 * or service providers are loaded.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Foundation
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class LoadEnvironmentVariables
{
    /**
     * Bootstrap environment variables.
     *
     * Loads .env file and merges with existing environment variables.
     * Docker/system environment variables take precedence over .env file.
     *
     * @param string $basePath Application base path
     * @return void
     */
    public static function bootstrap(string $basePath): void
    {
        $envFile = $basePath . '/.env';

        // Load .env file if exists
        if (file_exists($envFile)) {
            self::loadEnvFile($envFile);
        }

        // Merge system environment variables (Docker, system, etc.)
        self::mergeSystemEnvironment();
    }

    /**
     * Load environment variables from .env file.
     *
     * @param string $envFile Path to .env file
     * @return void
     */
    private static function loadEnvFile(string $envFile): void
    {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            // Parse KEY=VALUE
            if (strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            $value = self::unquoteValue($value);

            // Expand variables like ${VAR}
            $value = self::expandVariables($value);

            // Only set if not already set (system env vars take precedence)
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    /**
     * Remove quotes from value.
     *
     * Supports both single and double quotes.
     *
     * @param string $value
     * @return string
     */
    private static function unquoteValue(string $value): string
    {
        // Remove double quotes
        if (preg_match('/^"(.*)"$/', $value, $matches)) {
            return $matches[1];
        }

        // Remove single quotes
        if (preg_match("/^'(.*)'$/", $value, $matches)) {
            return $matches[1];
        }

        return $value;
    }

    /**
     * Expand environment variable references.
     *
     * Supports ${VAR} syntax.
     *
     * @param string $value
     * @return string
     */
    private static function expandVariables(string $value): string
    {
        return preg_replace_callback(
            '/\$\{([A-Z_][A-Z0-9_]*)\}/',
            function (array $matches): string {
                return $_ENV[$matches[1]] ?? '';
            },
            $value
        );
    }

    /**
     * Merge system environment variables into $_ENV.
     *
     * This ensures Docker environment variables and system environment
     * variables are available in $_ENV superglobal.
     *
     * @return void
     */
    private static function mergeSystemEnvironment(): void
    {
        foreach ($_SERVER as $key => $value) {
            // Skip HTTP headers (they start with HTTP_)
            if (strpos($key, 'HTTP_') === 0) {
                continue;
            }

            // Only merge string values
            if (!is_string($value)) {
                continue;
            }

            // Only set if not already in $_ENV
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
            }
        }
    }
}
