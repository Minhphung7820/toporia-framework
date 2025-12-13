<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Queue\QueueManager;
use Toporia\Framework\Queue\Contracts\JobInterface;

/**
 * Class Queue
 *
 * Queue Service Accessor - Provides static-like access to the queue manager.
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
 * @method static string push(JobInterface $job, string $queue = 'default') Push job to queue
 * @method static string later(JobInterface $job, int $delay, string $queue = 'default') Push job with delay
 * @method static JobInterface|null pop(string $queue = 'default') Pop job from queue
 * @method static QueueManager driver(?string $name = null) Get specific queue driver
 *
 * @see QueueManager
 *
 * @example
 * // Push job to queue
 * Queue::push(new SendEmailJob($user));
 *
 * // Push with delay (60 seconds)
 * Queue::later(new SendEmailJob($user), 60);
 *
 * // Use specific driver
 * Queue::driver('redis')->push($job);
 */
final class Queue extends ServiceAccessor
{
    protected static function getServiceName(): string
    {
        return 'queue';
    }
}
