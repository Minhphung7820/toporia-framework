<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\App;

use Toporia\Framework\Console\Command;

/**
 * Class EnvCommand
 *
 * Display the current framework environment.
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
final class EnvCommand extends Command
{
    protected string $signature = 'env';

    protected string $description = 'Display the current framework environment';

    public function handle(): int
    {
        $environment = env('APP_ENV', 'production');

        $this->info("Current application environment: {$environment}");

        return 0;
    }
}
