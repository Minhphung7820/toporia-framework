<?php

declare(strict_types=1);

namespace Toporia\Framework\Webhook\Jobs;

use Toporia\Framework\Queue\Job;
use Toporia\Framework\Webhook\Contracts\WebhookDispatcherInterface;

/**
 * Class DispatchWebhookJob
 *
 * Async job for dispatching webhooks via queue.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Webhook\Jobs
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class DispatchWebhookJob extends Job
{
    /**
     * @param string $event Event name
     * @param mixed $payload Event payload
     * @param string $endpoint Target URL
     * @param array<string, mixed> $options Options
     */
    public function __construct(
        private string $event,
        private mixed $payload,
        private string $endpoint,
        private array $options = []
    ) {
        parent::__construct();
    }

    /**
     * Handle the job execution.
     *
     * @param WebhookDispatcherInterface $dispatcher Webhook dispatcher
     * @return void
     */
    public function handle(WebhookDispatcherInterface $dispatcher): void
    {
        $dispatcher->dispatchTo($this->event, $this->payload, $this->endpoint, $this->options);
    }
}

