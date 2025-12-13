<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\App;

use Toporia\Framework\Console\Command;

/**
 * Class DownCommand
 *
 * Put the application into maintenance mode.
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
final class DownCommand extends Command
{
    protected string $signature = 'down {--message= : The message to display to users} {--retry= : The number of seconds after which the request may be retried} {--secret= : The secret phrase to bypass maintenance mode} {--status=503 : The status code to use}';

    protected string $description = 'Put the application into maintenance mode';

    public function handle(): int
    {
        $data = [
            'time' => now()->getTimestamp(),
            'message' => $this->option('message') ?: 'Service Unavailable',
            'retry' => $this->option('retry'),
            'secret' => $this->option('secret'),
            'status' => (int) ($this->option('status') ?: 503),
        ];

        $storagePath = $this->getBasePath() . '/storage/framework';

        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        $maintenancePath = $storagePath . '/down';

        file_put_contents($maintenancePath, json_encode($data, JSON_PRETTY_PRINT));

        $this->success('Application is now in maintenance mode.');

        if ($data['secret']) {
            $this->info("Secret: {$data['secret']}");
            $this->info("Bypass URL: " . config('app.url') . "/{$data['secret']}");
        }

        return 0;
    }

    private function getBasePath(): string
    {
        if (defined('APP_BASE_PATH')) {
            return constant('APP_BASE_PATH');
        }

        return getcwd() ?: dirname(__DIR__, 5);
    }
}
