<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification;

use Toporia\Framework\Notification\Contracts\{NotifiableInterface, NotificationInterface};

/**
 * Class AnonymousNotifiable
 *
 * Allows sending notifications to arbitrary channels without a model.
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
final class AnonymousNotifiable implements NotifiableInterface
{
    /**
     * @var array<string, mixed> Channel routes
     */
    private array $routes = [];

    /**
     * Set routing for a notification channel.
     *
     * @param string $channel Channel name
     * @param mixed $route Route value (email, phone, etc.)
     * @return $this
     */
    public function route(string $channel, mixed $route): self
    {
        $this->routes[$channel] = $route;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function routeNotificationFor(string $channel): mixed
    {
        return $this->routes[$channel] ?? null;
    }

    /**
     * Send a notification.
     *
     * @param NotificationInterface $notification
     * @return void
     */
    public function notify(NotificationInterface $notification): void
    {
        app('notification')->send($this, $notification);
    }

    /**
     * Convert to array for serialization.
     *
     * @return array
     */
    public function toArray(): array
    {
        return ['routes' => $this->routes];
    }

    /**
     * Reconstruct from array (for queue deserialization).
     *
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $instance = new self();
        $instance->routes = $data['routes'] ?? [];
        return $instance;
    }
}
