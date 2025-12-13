<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakeExceptionCommand
 *
 * Create a new custom exception class.
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
final class MakeExceptionCommand extends GeneratorCommand
{
    protected string $signature = 'make:exception {name : The name of the exception}';

    protected string $description = 'Create a new custom exception class';

    protected string $type = 'Exception';

    protected function getStub(): string
    {
        return $this->resolveStubPath('exception.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Domain\\Exceptions';
    }
}
