<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification;

use Toporia\Framework\Notification\Contracts\{NotificationInterface, NotifiableInterface};


/**
 * Abstract Class Notification
 *
 * Abstract base class for Notification implementations in the
 * Multi-channel notifications layer providing common functionality and
 * contracts.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Notification
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class Notification implements NotificationInterface
{
    protected string $id;
    protected bool $shouldQueue = false;
    protected string $queueName = 'notifications';
    protected int $delay = 0;

    public function __construct()
    {
        $this->id = uniqid('notification_', true);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function via(NotifiableInterface $notifiable): array;

    /**
     * {@inheritdoc}
     *
     * Routes to channel-specific methods:
     * - 'mail' → toMail()
     * - 'database' → toDatabase()
     * - 'sms' → toSms()
     * - 'slack' → toSlack()
     */
    public function toChannel(NotifiableInterface $notifiable, string $channel): mixed
    {
        $method = 'to' . ucfirst($channel);

        if (!method_exists($this, $method)) {
            throw new \BadMethodCallException(
                "Notification " . static::class . " is missing toChannel method for '{$channel}'"
            );
        }

        return $this->$method($notifiable);
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function shouldQueue(): bool
    {
        return $this->shouldQueue;
    }

    /**
     * {@inheritdoc}
     */
    public function getQueueName(): string
    {
        return $this->queueName;
    }

    /**
     * {@inheritdoc}
     */
    public function getDelay(): int
    {
        return $this->delay;
    }

    /**
     * Set notification to be queued.
     *
     * @param string $queueName Queue name
     * @return $this
     */
    public function onQueue(string $queueName): self
    {
        $this->shouldQueue = true;
        $this->queueName = $queueName;
        return $this;
    }

    /**
     * Set notification delay.
     *
     * @param int $seconds Delay in seconds
     * @return $this
     */
    public function delay(int $seconds): self
    {
        $this->delay = $seconds;
        return $this;
    }
}
