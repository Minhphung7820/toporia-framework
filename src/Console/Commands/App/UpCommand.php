<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\App;

use Toporia\Framework\Console\Command;

/**
 * Class UpCommand
 *
 * Bring the application out of maintenance mode.
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
final class UpCommand extends Command
{
    protected string $signature = 'up';

    protected string $description = 'Bring the application out of maintenance mode';

    public function handle(): int
    {
        $maintenancePath = $this->getBasePath() . '/storage/framework/down';

        if (!file_exists($maintenancePath)) {
            $this->info('Application is already up.');
            return 0;
        }

        unlink($maintenancePath);

        $this->success('Application is now live.');

        return 0;
    }

        return getcwd() ?: dirname(__DIR__, 5);
    }
}
