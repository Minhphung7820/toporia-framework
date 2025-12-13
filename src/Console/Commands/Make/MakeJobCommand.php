<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakeJobCommand
 *
 * Create a new queueable job class.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\Make
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MakeJobCommand extends GeneratorCommand
{
    protected string $signature = 'make:job {name : The name of the job} {--sync : Indicates that job should be synchronous}';

    protected string $description = 'Create a new queue job class';

    protected string $type = 'Job';

    protected function getStub(): string
    {
        return $this->resolveStubPath('job.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Application\\Jobs';
    }
}
