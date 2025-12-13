<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakeActionCommand
 *
 * Create a new action class.
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
final class MakeActionCommand extends GeneratorCommand
{
    protected string $signature = 'make:action {name : The name of the action}';

    protected string $description = 'Create a new ADR-style action class';

    protected string $type = 'Action';

    protected function getStub(): string
    {
        return $this->resolveStubPath('action.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Presentation\\Http\\Actions';
    }
}
