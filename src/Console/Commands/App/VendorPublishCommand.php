<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\App;

use Toporia\Framework\Console\Command;

/**
 * Class VendorPublishCommand
 *
 * Publish any publishable assets from vendor packages.
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
final class VendorPublishCommand extends Command
{
    protected string $signature = 'vendor:publish {--tag= : The tag to publish} {--force : Overwrite existing files} {--all : Publish all publishable assets}';

    protected string $description = 'Publish any publishable assets from vendor packages';

    /**
     * Available publish tags and their sources.
     *
     * @var array<string, array{source: string, destination: string}>
     */
    private array $publishables = [
        // Dominion (RBAC) package
        'dominion-config' => [
            'source' => 'vendor/toporia/dominion/config/dominion.php',
            'destination' => 'config/dominion.php',
        ],
        // MongoDB package
        'mongodb-config' => [
            'source' => 'vendor/toporia/mongodb/config/mongodb.php',
            'destination' => 'config/mongodb.php',
        ],
        // Socialite package
        'socialite-config' => [
            'source' => 'vendor/toporia/socialite/config/socialite.php',
            'destination' => 'config/socialite.php',
        ],
        // Webhook package
        'webhook-config' => [
            'source' => 'vendor/toporia/webhook/config/webhook.php',
            'destination' => 'config/webhook.php',
        ],
        // Tenancy package
        'tenancy-config' => [
            'source' => 'vendor/toporia/tenancy/config/tenancy.php',
            'destination' => 'config/tenancy.php',
        ],
        // Audit package
        'audit-config' => [
            'source' => 'vendor/toporia/audit/config/audit.php',
            'destination' => 'config/audit.php',
        ],
        // API Versioning package
        'api-config' => [
            'source' => 'vendor/toporia/api-versioning/config/api.php',
            'destination' => 'config/api.php',
        ],
    ];

    public function handle(): int
    {
        $tag = $this->option('tag');
        $force = $this->option('force');
        $all = $this->option('all');

        if (!$tag && !$all) {
            $this->showAvailableTags();
            return 0;
        }

        $basePath = $this->getBasePath();
        $published = 0;
        $skipped = 0;
        $failed = 0;

        $tagsToPublish = $all ? array_keys($this->publishables) : [$tag];

        foreach ($tagsToPublish as $currentTag) {
            if (!isset($this->publishables[$currentTag])) {
                $this->warn("Tag [{$currentTag}] is not publishable.");
                continue;
            }

            $config = $this->publishables[$currentTag];
            $sourcePath = $basePath . '/' . $config['source'];
            $destPath = $basePath . '/' . $config['destination'];

            // Also check in packages directory (for development)
            if (!file_exists($sourcePath)) {
                $packageSource = str_replace('vendor/toporia/', 'packages/', $config['source']);
                $sourcePath = $basePath . '/' . $packageSource;
            }

            if (!file_exists($sourcePath)) {
                $this->warn("Source file not found for tag [{$currentTag}]. Package may not be installed.");
                $failed++;
                continue;
            }

            if (file_exists($destPath) && !$force) {
                $this->warn("File [{$config['destination']}] already exists. Use --force to overwrite.");
                $skipped++;
                continue;
            }

            // Ensure destination directory exists
            $destDir = dirname($destPath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            if (copy($sourcePath, $destPath)) {
                $this->info("Published: {$config['destination']}");
                $published++;
            } else {
                $this->error("Failed to publish: {$config['destination']}");
                $failed++;
            }
        }

        $this->newLine();

        if ($published > 0) {
            $this->success("Published {$published} file(s).");
        }

        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} existing file(s). Use --force to overwrite.");
        }

        if ($failed > 0) {
            $this->error("Failed to publish {$failed} file(s).");
        }

        if ($published === 0 && $skipped === 0 && $failed === 0) {
            $this->info('Nothing to publish.');
        }

        return $failed > 0 ? 1 : 0;
    }

    /**
     * Show available publish tags.
     */
    private function showAvailableTags(): void
    {
        $this->info('Available publish tags:');
        $this->newLine();

        foreach ($this->publishables as $tag => $config) {
            $this->writeln("  {$tag}");
            $this->writeln("    Source: {$config['source']}");
            $this->writeln("    Destination: {$config['destination']}");
            $this->newLine();
        }

        $this->newLine();
        $this->info('Usage:');
        $this->writeln('  php console vendor:publish --tag=socialite-config');
        $this->writeln('  php console vendor:publish --tag=webhook-config --force');
        $this->writeln('  php console vendor:publish --all');
    }
}
