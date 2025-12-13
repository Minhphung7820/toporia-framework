<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Foundation\Application;

/**
 * Class ConfigClearCommand
 *
 * Clear compiled configuration cache.
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
final class ConfigClearCommand extends Command
{
    protected string $signature = 'config:clear';
    protected string $description = 'Clear configuration cache';

    public function __construct(
        private readonly Application $app
    ) {}

    public function handle(): int
    {
        $cachePath = $this->app->path('storage/framework/config.php');

        if (!file_exists($cachePath)) {
            $this->info('Configuration cache is already clear.');
            return 0;
        }

        try {
            @unlink($cachePath);
            $this->success('Configuration cache cleared successfully!');
            return 0;
        } catch (\Throwable $e) {
            $this->error("Failed to clear configuration cache: {$e->getMessage()}");
            return 1;
        }
    }
}
