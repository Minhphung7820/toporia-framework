<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Cache\Contracts\CacheManagerInterface;

/**
 * Class CacheClearCommand
 *
 * Clear application cache.
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
final class CacheClearCommand extends Command
{
    protected string $signature = 'cache:clear';
    protected string $description = 'Clear application cache';

    public function __construct(
        private readonly CacheManagerInterface $cacheManager
    ) {}

    public function handle(): int
    {
        $store = $this->option('store');

        try {
            if ($store) {
                $this->info("Clearing '{$store}' cache...");
                $this->cacheManager->driver($store)->clear();
            } else {
                $this->info('Clearing default cache...');
                $this->cacheManager->driver()->clear();
            }

            $this->success('Cache cleared successfully!');

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to clear cache: {$e->getMessage()}");
            return 1;
        }
    }
}
