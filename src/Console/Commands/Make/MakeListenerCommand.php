<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakeListenerCommand
 *
 * Create a new event listener class.
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
final class MakeListenerCommand extends GeneratorCommand
{
    protected string $signature = 'make:listener {name : The name of the listener} {--event= : The event class being listened for}';

    protected string $description = 'Create a new event listener class';

    protected string $type = 'Listener';

    protected function getStub(): string
    {
        return $this->resolveStubPath('listener.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Application\\Listeners';
    }

    protected function buildClass(string $name): string
    {
        $stub = $this->getStubContent();

        $this->replaceNamespace($stub, $name);
        $stub = $this->replaceClass($stub, $name);

        // Handle event class
        $eventClass = $this->option('event');
        if (empty($eventClass)) {
            $eventClass = 'App\\Domain\\Events\\SomeEvent';
        } elseif (!str_contains($eventClass, '\\')) {
            $eventClass = 'App\\Domain\\Events\\' . $eventClass;
        }

        $eventShortClass = class_basename($eventClass);

        $stub = str_replace(['{{ eventClass }}', '{{eventClass}}'], $eventClass, $stub);
        $stub = str_replace(['{{ eventShortClass }}', '{{eventShortClass}}'], $eventShortClass, $stub);

        return $stub;
    }
}

if (!function_exists('class_basename')) {
    function class_basename(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}
