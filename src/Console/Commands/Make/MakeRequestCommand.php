<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakeRequestCommand
 *
 * Create a new form request class for validation.
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
final class MakeRequestCommand extends GeneratorCommand
{
    protected string $signature = 'make:request {name : The name of the form request}';

    protected string $description = 'Create a new form request class';

    protected string $type = 'Request';

    protected function getStub(): string
    {
        return $this->resolveStubPath('request.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Presentation\\Http\\Requests';
    }
}
