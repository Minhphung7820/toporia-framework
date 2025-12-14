<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Queue;

use Toporia\Framework\Console\Command;

/**
 * Class QueueRestartCommand
 *
 * Restart queue worker daemons.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\Queue
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class QueueRestartCommand extends Command
{
    protected string $signature = 'queue:restart';

    protected string $description = 'Restart queue worker daemons after their current job';

    public function handle(): int
    {
        $cachePath = $this->getBasePath() . '/storage/framework/cache';

        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        file_put_contents($cachePath . '/queue-restart', (string) now()->getTimestamp());

        $this->success('Broadcasting queue restart signal.');

        return 0;
    }

        return getcwd() ?: dirname(__DIR__, 5);
    }
}
