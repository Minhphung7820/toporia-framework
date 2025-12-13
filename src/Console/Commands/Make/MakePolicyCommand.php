<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakePolicyCommand
 *
 * Create a new authorization policy class.
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
final class MakePolicyCommand extends GeneratorCommand
{
    protected string $signature = 'make:policy {name : The name of the policy} {--model= : The model that the policy applies to}';

    protected string $description = 'Create a new policy class';

    protected string $type = 'Policy';

    protected function getStub(): string
    {
        return $this->resolveStubPath('policy.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Application\\Policies';
    }
}
