<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakeHandlerCommand
 *
 * Create a new handler class for processing commands.
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
final class MakeHandlerCommand extends GeneratorCommand
{
    protected string $signature = 'make:handler {name : The name of the handler} {--command= : The command class that this handler handles}';

    protected string $description = 'Create a new command/query handler class';

    protected string $type = 'Handler';

    protected function getStub(): string
    {
        return $this->resolveStubPath('handler.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Application\\Handlers';
    }

    protected function buildClass(string $name): string
    {
        $stub = $this->getStubContent();

        $this->replaceNamespace($stub, $name);
        $stub = $this->replaceClass($stub, $name);

        // Handle command class
        $commandClass = $this->option('command');
        if (empty($commandClass)) {
            // Try to derive from handler name
            $className = $this->getClassName($name);
            $commandName = str_replace('Handler', 'Command', $className);
            $commandClass = 'App\\Application\\Commands\\' . $commandName;
        } elseif (!str_contains($commandClass, '\\')) {
            $commandClass = 'App\\Application\\Commands\\' . $commandClass;
        }

        $commandShortClass = $this->getClassBasename($commandClass);

        $stub = str_replace(['{{ commandClass }}', '{{commandClass}}'], $commandClass, $stub);
        $stub = str_replace(['{{ commandShortClass }}', '{{commandShortClass}}'], $commandShortClass, $stub);

        return $stub;
    }

    private function getClassBasename(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}
