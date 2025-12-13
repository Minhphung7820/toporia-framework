<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Event;

use Toporia\Framework\Console\Command;

/**
 * Class EventListCommand
 *
 * List all registered events and their listeners in the application.
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
final class EventListCommand extends Command
{
    protected string $signature = 'event:list {--event= : Filter the events by name}';

    protected string $description = 'List all registered events and their listeners';

    public function handle(): int
    {
        $this->info('Listing registered events and listeners...');
        $this->info('This command requires EventDispatcher to be configured.');

        return 0;
    }
}
