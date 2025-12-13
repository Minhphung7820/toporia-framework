<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Optimize;

use Toporia\Framework\Console\Command;

/**
 * Class ViewClearCommand
 *
 * Clear all compiled view files.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\Optimize
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ViewClearCommand extends Command
{
    protected string $signature = 'view:clear';

    protected string $description = 'Clear all compiled view files';

    public function handle(): int
    {
        $cachePath = $this->getBasePath() . '/storage/framework/views';

        if (!is_dir($cachePath)) {
            $this->info('No compiled views to clear.');
            return 0;
        }

        $files = glob($cachePath . '/*.php');
        $count = count($files);

        foreach ($files as $file) {
            unlink($file);
        }

        $this->success("Cleared {$count} compiled view(s).");

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
