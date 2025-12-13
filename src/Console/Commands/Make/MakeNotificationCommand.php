<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Make;

use Toporia\Framework\Console\Generator\GeneratorCommand;

/**
 * Class MakeNotificationCommand
 *
 * Create a new notification class.
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
final class MakeNotificationCommand extends GeneratorCommand
{
    protected string $signature = 'make:notification {name : The name of the notification}';

    protected string $description = 'Create a new notification class';

    protected string $type = 'Notification';

    protected function getStub(): string
    {
        return $this->resolveStubPath('notification.stub');
    }

    protected function getDefaultNamespace(): string
    {
        return 'App\\Application\\Notifications';
    }
}
