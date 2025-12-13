<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Foundation\Application;

/**
 * Class KeyGenerateCommand
 *
 * Generates a new application encryption key (APP_KEY) for secure operations.
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
final class KeyGenerateCommand extends Command
{
    /**
     * Command signature.
     *
     * @var string
     */
    protected string $signature = 'key:generate {--show : Display the key instead of modifying files}';

    /**
     * Command description.
     *
     * @var string
     */
    protected string $description = 'Set the application key';

    /**
     * @param Application $app Application instance
     */
    public function __construct(
        private readonly Application $app
    ) {}

    /**
     * Execute the command.
     *
     * @return int Exit code
     */
    public function handle(): int
    {
        $key = $this->generateRandomKey();

        if ($this->option('show')) {
            $this->writeln('Generated key: ' . $key);
            return 0;
        }

        // Update .env file
        $envPath = $this->getEnvPath();

        if (!file_exists($envPath)) {
            $this->error('.env file not found. Please create .env file first.');
            return 1;
        }

        $envContent = file_get_contents($envPath);

        // Check if APP_KEY already exists
        if (preg_match('/^APP_KEY=(.*)$/m', $envContent)) {
            // Replace existing APP_KEY
            $envContent = preg_replace(
                '/^APP_KEY=.*$/m',
                'APP_KEY=' . $key,
                $envContent
            );
        } else {
            // Append APP_KEY if not exists
            $envContent .= "\nAPP_KEY={$key}\n";
        }

        // Write back to .env
        if (file_put_contents($envPath, $envContent) === false) {
            $this->error('Failed to write to .env file.');
            return 1;
        }

        $this->info('Application key set successfully.');
        $this->writeln('Key: ' . $key);

        return 0;
    }

    /**
     * Generate a random encryption key.
     *
     * @return string Base64 encoded key
     */
    private function generateRandomKey(): string
    {
        // Generate 32 random bytes (256 bits)
        $bytes = random_bytes(32);

        // Encode to base64 for .env file
        return 'base64:' . base64_encode($bytes);
    }

    /**
     * Get .env file path.
     *
     * @return string Path to .env file
     */
    private function getEnvPath(): string
    {
        // Get base path from Application instance
        return $this->app->getBasePath() . DIRECTORY_SEPARATOR . '.env';
    }
}
