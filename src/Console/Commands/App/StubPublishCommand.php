<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\App;

use Toporia\Framework\Console\Command;

/**
 * Class StubPublishCommand
 *
 * Publish all stubs that are available for customization.
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
final class StubPublishCommand extends Command
{
    protected string $signature = 'stub:publish {--force : Overwrite any existing files}';

    protected string $description = 'Publish all stubs that are available for customization';

    public function handle(): int
    {
        $stubsPath = $this->getBasePath() . '/stubs';

        if (!is_dir($stubsPath)) {
            mkdir($stubsPath, 0755, true);
        }

        $frameworkStubsPath = dirname(__DIR__, 3) . '/Console/stubs';

        // If framework stubs don't exist, check alternative location
        if (!is_dir($frameworkStubsPath)) {
            $frameworkStubsPath = $this->getBasePath() . '/stubs';

            if (!is_dir($frameworkStubsPath)) {
                $this->info('No stubs to publish.');
                return 0;
            }
        }

        $published = 0;
        $skipped = 0;

        $files = glob($frameworkStubsPath . '/*.stub');

        foreach ($files as $file) {
            $filename = basename($file);
            $destination = $stubsPath . '/' . $filename;

            if (file_exists($destination) && !$this->option('force')) {
                $skipped++;
                continue;
            }

            copy($file, $destination);
            $published++;
            $this->info("Published: {$filename}");
        }

        $this->newLine();

        if ($published > 0) {
            $this->success("Published {$published} stub(s).");
        }

        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} existing stub(s). Use --force to overwrite.");
        }

        if ($published === 0 && $skipped === 0) {
            $this->info('No stubs to publish.');
        }

        return 0;
    }
}
