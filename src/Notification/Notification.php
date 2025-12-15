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
 * Usage:
 * ```php
 * class OrderShipped extends Notification
 * {
 *     public function __construct(private Order $order) {
 *         parent::__construct();
 *     }
 *
 *     public function via(NotifiableInterface $notifiable): array {
 *         return ['mail', 'database', 'broadcast'];
 *     }
 *
 *     public function shouldSend(NotifiableInterface $notifiable): bool {
 *         return $notifiable->wantsNotifications('orders');
 *     }
 *
 *     public function toMail(NotifiableInterface $notifiable): MailMessage {
 *         return (new MailMessage)
 *             ->subject('Order Shipped')
 *             ->line("Your order #{$this->order->id} has been shipped.");
 *     }
 * }
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.1.0
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

    /** @var string|null Queue connection name */
    protected ?string $connection = null;

    /** @var int Number of retry attempts */
    protected int $tries = 3;

    /** @var int Seconds before retry */
    protected int $retryAfter = 60;

    /** @var string|null Locale for notification */
    protected ?string $locale = null;

    public function __construct()
    {
        $this->id = self::generateUuid();
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
     * - 'broadcast' → toBroadcast()
     */
    public function toChannel(NotifiableInterface $notifiable, string $channel): mixed
    {
        $method = 'to' . ucfirst($channel);

        if (!method_exists($this, $method)) {
            throw new \BadMethodCallException(
                sprintf(
                    "Notification %s is missing '%s()' method for channel '%s'",
                    static::class,
                    $method,
                    $channel
                )
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
     *
     * Override this method in your notification to add conditional logic.
     *
     * Examples:
     * - Check user notification preferences
     * - Implement rate limiting
     * - Check feature flags
     * - Business rules
     */
    public function shouldSend(NotifiableInterface $notifiable): bool
    {
        return true;
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
     * Get queue connection name.
     *
     * @return string|null
     */
    public function getConnection(): ?string
    {
        return $this->connection;
    }

    /**
     * Get number of retry attempts.
     *
     * @return int
     */
    public function getTries(): int
    {
        return $this->tries;
    }

    /**
     * Get retry delay in seconds.
     *
     * @return int
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    /**
     * Get notification locale.
     *
     * @return string|null
     */
    public function getLocale(): ?string
    {
        return $this->locale;
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
     * Set queue connection.
     *
     * @param string $connection
     * @return $this
     */
    public function onConnection(string $connection): self
    {
        $this->connection = $connection;
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
        $this->delay = max(0, $seconds);
        return $this;
    }

    /**
     * Alias for delay() using DateInterval-like syntax.
     *
     * @param \DateTimeInterface|\DateInterval|int $delay
     * @return $this
     */
    public function later(\DateTimeInterface|\DateInterval|int $delay): self
    {
        if ($delay instanceof \DateTimeInterface) {
            $this->delay = max(0, $delay->getTimestamp() - time());
        } elseif ($delay instanceof \DateInterval) {
            $reference = new \DateTimeImmutable();
            $endTime = $reference->add($delay);
            $this->delay = max(0, $endTime->getTimestamp() - $reference->getTimestamp());
        } else {
            $this->delay = max(0, $delay);
        }

        return $this;
    }

    /**
     * Set retry configuration.
     *
     * @param int $tries Number of attempts
     * @param int $retryAfter Seconds between retries
     * @return $this
     */
    public function retries(int $tries, int $retryAfter = 60): self
    {
        $this->tries = max(1, $tries);
        $this->retryAfter = max(0, $retryAfter);
        return $this;
    }

    /**
     * Set notification locale.
     *
     * @param string $locale
     * @return $this
     */
    public function locale(string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    /**
     * Generate UUID v4 for notification ID.
     *
     * Performance: O(1) - Uses random_bytes for cryptographic randomness
     *
     * @return string UUID v4 format
     */
    public static function generateUuid(): string
    {
        // Generate 16 random bytes
        $data = random_bytes(16);

        // Set version to 4 (random UUID)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);

        // Set variant to RFC 4122
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Format as UUID string
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Create a notification instance.
     *
     * Static factory method for fluent creation.
     *
     * @param mixed ...$args Constructor arguments
     * @return static
     */
    public static function make(mixed ...$args): static
    {
        return new static(...$args);
    }
}
