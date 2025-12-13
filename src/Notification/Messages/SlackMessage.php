<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Messages;

/**
 * Class SlackMessage
 *
 * Fluent builder for Slack notifications.
 * Provides a simple API for constructing rich Slack messages with attachments.
 *
 * Usage:
 * ```php
 * return (new SlackMessage)
 *     ->text('Order Shipped!')
 *     ->attachment(function ($attachment) {
 *         $attachment
 *             ->title('Order #123')
 *             ->text('Your order has been shipped')
 *             ->color('good')
 *             ->fields([
 *                 'Tracking Number' => 'TRACK123',
 *                 'Estimated Delivery' => '2025-01-25'
 *             ]);
 *     });
 * ```
 *
 * Performance:
 * - O(1) for each fluent call
 * - Lazy evaluation (data built only when needed)
 * - Minimal memory footprint
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Notification\Messages
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SlackMessage
{
    private string $text = '';
    private array $attachments = [];
    private ?string $username = null;
    private ?string $channel = null;
    private ?string $iconEmoji = null;

    /**
     * Set message text.
     *
     * @param string $text
     * @return $this
     */
    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    /**
     * Add an attachment.
     *
     * @param callable|SlackAttachment $attachment
     * @return $this
     */
    public function attachment(callable|SlackAttachment $attachment): self
    {
        if ($attachment instanceof SlackAttachment) {
            $this->attachments[] = $attachment;
        } else {
            $slackAttachment = new SlackAttachment();
            $attachment($slackAttachment);
            $this->attachments[] = $slackAttachment;
        }

        return $this;
    }

    /**
     * Set username (bot name).
     *
     * @param string $username
     * @return $this
     */
    public function username(string $username): self
    {
        $this->username = $username;
        return $this;
    }

    /**
     * Set channel override.
     *
     * @param string $channel
     * @return $this
     */
    public function channel(string $channel): self
    {
        $this->channel = $channel;
        return $this;
    }

    /**
     * Set icon emoji.
     *
     * @param string $emoji Emoji string (e.g., ':rocket:', ':warning:')
     * @return $this
     */
    public function iconEmoji(string $emoji): self
    {
        $this->iconEmoji = $emoji;
        return $this;
    }

    /**
     * Convert message to array format for Slack webhook.
     *
     * @return array
     */
    public function toArray(): array
    {
        $payload = array_filter([
            'text' => $this->text,
            'username' => $this->username,
            'channel' => $this->channel,
            'icon_emoji' => $this->iconEmoji,
        ], fn($value) => $value !== null && $value !== '');

        if (!empty($this->attachments)) {
            $payload['attachments'] = array_map(
                fn(SlackAttachment $attachment) => $attachment->toArray(),
                $this->attachments
            );
        }

        return $payload;
    }
}

/**
 * Class SlackAttachment
 *
 * Helper class for Slack message attachments.
 *
 * @package Toporia\Framework\Notification\Messages
 */
class SlackAttachment
{
    public string $title = '';
    public string $text = '';
    public string $color = 'good';
    public array $fields = [];

    public function title(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function color(string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function fields(array $fields): self
    {
        foreach ($fields as $key => $value) {
            $this->fields[] = [
                'title' => $key,
                'value' => $value,
                'short' => true
            ];
        }

        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'text' => $this->text,
            'color' => $this->color,
            'fields' => $this->fields,
        ]);
    }
}
