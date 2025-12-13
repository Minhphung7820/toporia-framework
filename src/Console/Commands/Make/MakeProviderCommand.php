<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakeProviderCommand
 *
 * Create a new service provider class.
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
final class MakeProviderCommand extends GeneratorCommand
{
    protected string $signature = 'make:provider {name : The name of the service provider}';

    protected string $description = 'Create a new service provider class';

    protected string $type = 'Provider';

    protected function getStub(): string
    {
        return $this->resolveStubPath('provider.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Infrastructure\\Providers';
    }
}
