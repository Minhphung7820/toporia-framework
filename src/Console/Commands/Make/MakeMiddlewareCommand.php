<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakeMiddlewareCommand
 *
 * Create a new middleware class for request filtering.
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
final class MakeMiddlewareCommand extends GeneratorCommand
{
    protected string $signature = 'make:middleware {name : The name of the middleware}';

    protected string $description = 'Create a new middleware class';

    protected string $type = 'Middleware';

    protected function getStub(): string
    {
        return $this->resolveStubPath('middleware.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Presentation\\Http\\Middleware';
    }
}
