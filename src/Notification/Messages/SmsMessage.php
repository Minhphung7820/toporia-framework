<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Messages;

/**
 * Class SmsMessage
 *
 * Simple SMS message builder with character count tracking.
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
final class SmsMessage
{
    public string $content = '';
    public ?string $from = null;

    /**
     * Set message content.
     *
     * @param string $content
     * @return $this
     */
    public function content(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Set sender ID/name.
     *
     * @param string $from
     * @return $this
     */
    public function from(string $from): self
    {
        $this->from = $from;
        return $this;
    }

    /**
     * Get message character count.
     *
     * @return int
     */
    public function getLength(): int
    {
        return mb_strlen($this->content);
    }

    /**
     * Check if message exceeds SMS limit (160 characters).
     *
     * @return bool
     */
    public function exceedsLimit(): bool
    {
        return $this->getLength() > 160;
    }
}
