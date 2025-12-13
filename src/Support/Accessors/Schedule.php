<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Console\Scheduling\{ScheduledTask, Scheduler};
use Toporia\Framework\Queue\Contracts\JobInterface;

/**
 * Class Schedule
 *
 * Schedule Service Accessor - Provides static-like access to the task scheduler.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Accessors
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @method static ScheduledTask call(callable $callback, array $parameters = []) Schedule callback
 * @method static ScheduledTask exec(string $command) Schedule shell command
 * @method static ScheduledTask job(JobInterface $job, string $queue = 'default') Schedule queue job
 * @method static void runDueTasks() Run all due tasks
 * @method static array getTasks() Get all scheduled tasks
 *
 * @see Scheduler
 *
 * @example
 * // Schedule callback
 * Schedule::call(function() {
 *     // Cleanup old files
 * })->daily();
 *
 * // Schedule command
 * Schedule::exec('php console cache:clear')->everyMinute();
 *
 * // Schedule job
 * Schedule::job(new SendNewsletterJob())->weekly();
 */
final class Schedule extends ServiceAccessor
{
    protected static function getServiceName(): string
    {
        return 'schedule';
    }
}
