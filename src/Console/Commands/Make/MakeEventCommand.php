<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakeEventCommand
 *
 * Create a new event class.
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
final class MakeEventCommand extends GeneratorCommand
{
    protected string $signature = 'make:event {name : The name of the event}';

    protected string $description = 'Create a new event class';

    protected string $type = 'Event';

    protected function getStub(): string
    {
        return $this->resolveStubPath('event.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Domain\\Events';
    }

    protected function buildClass(string $name): string
    {
        $stub = $this->getStubContent();

        $this->replaceNamespace($stub, $name);
        $stub = $this->replaceClass($stub, $name);

        // Generate event name from class name
        $className = $this->getClassName($name);
        $eventName = $this->generateEventName($className);
        $stub = str_replace(['{{ eventName }}', '{{eventName}}'], $eventName, $stub);

        return $stub;
    }

    private function generateEventName(string $className): string
    {
        // Convert CamelCase to dot.notation
        $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1.$2', $className));
        return $snake;
    }
}
