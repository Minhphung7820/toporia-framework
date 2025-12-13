<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakeControllerCommand
 *
 * Create a new controller class for handling HTTP requests.
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
final class MakeControllerCommand extends GeneratorCommand
{
    protected string $signature = 'make:controller {name : The name of the controller} {--resource : Generate a resource controller} {--api : Generate an API controller} {--invokable : Generate a single action controller}';

    protected string $description = 'Create a new controller class';

    protected string $type = 'Controller';

    protected function getStub(): string
    {
        if ($this->option('api')) {
            return $this->resolveStubPath('controller.api.stub');
        }

        if ($this->option('resource')) {
            return $this->resolveStubPath('controller.resource.stub');
        }

        if ($this->option('invokable')) {
            return $this->resolveStubPath('controller.invokable.stub');
        }

        return $this->resolveStubPath('controller.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Presentation\\Http\\Controllers';
    }

    protected function buildClass(string $name): string
    {
        $stub = $this->getStubContent();

        $this->replaceNamespace($stub, $name);
        $stub = $this->replaceClass($stub, $name);

        if ($this->option('resource')) {
            $className = $this->getClassName($name);
            $baseName = str_replace('Controller', '', $className);
            $viewPath = strtolower($baseName);
            $routeName = strtolower($baseName);

            $this->replaceVariable($stub, 'viewPath', $viewPath);
            $this->replaceVariable($stub, 'routeName', $routeName);
        }

        return $stub;
    }
}
