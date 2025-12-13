<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakeEntityCommand
 *
 * Create a new entity class.
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
final class MakeEntityCommand extends GeneratorCommand
{
    protected string $signature = 'make:entity {name : The name of the entity}';

    protected string $description = 'Create a new domain entity class';

    protected string $type = 'Entity';

    protected function getStub(): string
    {
        return $this->resolveStubPath('entity.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Domain';
    }
}
