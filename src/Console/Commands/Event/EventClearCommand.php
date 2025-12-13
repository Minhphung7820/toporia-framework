<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Event;

use Toporia\Framework\Console\Command;

/**
 * Class EventClearCommand
 *
 * Clear all cached events and listeners.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\Event
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class EventClearCommand extends Command
{
    protected string $signature = 'event:clear';

    protected string $description = 'Clear all cached events and listeners';

    public function handle(): int
    {
        $cachePath = $this->getCachePath();

        if (file_exists($cachePath)) {
            unlink($cachePath);
            $this->success('Cached events cleared.');
        } else {
            $this->info('No event cache to clear.');
        }

        return 0;
    }

    private function getCachePath(): string
    {
        $basePath = defined('APP_BASE_PATH') ? constant('APP_BASE_PATH') : (getcwd() ?: dirname(__DIR__, 5));
        return $basePath . '/bootstrap/cache/events.php';
    }
}
